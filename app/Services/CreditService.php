<?php

namespace App\Services;

use App\Helpers\Helper;
use Illuminate\Support\Facades\Hash;
use App\Models\Client;
use Illuminate\Support\Facades\Log;
use App\Models\Guarantor;
use Illuminate\Support\Str;
use App\Models\Credit;
use App\Http\Requests\Credit\CreditRequest;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Installment;
use App\Models\Liquidation;
use App\Models\Payment;
use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\CreditModification;
use App\Models\PaymentInstallment;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Traits\ApiResponse;

class CreditService
{
    use ApiResponse;

    const TIMEZONE = 'America/Lima';

    public function create(CreditRequest $request)
    {
        try {
            $params = $request->validated();
            \Log::info('Creando crédito con parámetros: ' . json_encode($params));
            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['created_at'] = Carbon::now($params['timezone']);
                $params['updated_at'] = Carbon::now($params['timezone']);
                $userTimezone = $params['timezone'];
                unset($params['timezone']);
            } else {
                $userTimezone = null;
            }

            // Calcular fecha de primera cuota
            if (isset($params['first_quota_date']) && !empty($params['first_quota_date'])) {
                $firstQuotaDate = $params['first_quota_date'];
            } elseif ($params['is_advance_payment'] ?? false) {
                $firstQuotaDate = now()->format('Y-m-d');
            } else {
                $today = now();
                switch ($params['payment_frequency']) {
                    case 'Diaria':
                        $firstQuotaDate = $today->addDay()->format('Y-m-d');
                        break;
                    case 'Semanal':
                        $firstQuotaDate = $today->addWeek()->format('Y-m-d');
                        break;
                    case 'Quincenal':
                        $firstQuotaDate = $today->addDays(15)->format('Y-m-d');
                        break;
                    case 'Mensual':
                        $firstQuotaDate = $today->addMonth()->format('Y-m-d');
                        break;
                    default:
                        $firstQuotaDate = $today->addDay()->format('Y-m-d');
                }
            }

            // Restricción por monto total de ventas nuevas en el día
            $sellerConfig = \App\Models\SellerConfig::where('seller_id', $params['seller_id'])->first();
            $limit = $sellerConfig ? floatval($sellerConfig->restrict_new_sales_amount ?? 0) : 0;
            if ($limit > 0) {
                $tz = $userTimezone ?: self::TIMEZONE;
                $startUTC = Carbon::now($tz)->startOfDay()->setTimezone('UTC');
                $endUTC = Carbon::now($tz)->endOfDay()->setTimezone('UTC');

                $newCreditsAmount = \App\Models\Credit::where('seller_id', $params['seller_id'])
                    ->whereBetween('created_at', [$startUTC, $endUTC])
                    ->sum('credit_value');
                $totalWithNew = $newCreditsAmount + floatval($params['credit_value']);
                if ($totalWithNew > $limit) {
                    return $this->errorResponse('No puedes crear el crédito. El monto total de ventas nuevas por el cobrador hoy supera el límite de $' . number_format($limit, 2), 403);
                }
            }

            // Calculate total interest amount
            $interestRate = floatval($params['interest_rate'] ?? 0);
            $creditValue = floatval($params['credit_value'] ?? 0);
            $totalInterestAmount = ($creditValue * $interestRate) / 100;

            // Calculate micro insurance amount
            $microInsurancePercentage = floatval($params['micro_insurance_percentage'] ?? 0);
            // Always calculate based on value and percentage to ensure accuracy and prevent frontend calculation errors from persisting
            $microInsuranceAmount = ($creditValue * $microInsurancePercentage) / 100;

            // Calculate total amount (Capital + Interest only, Micro-insurance is deducted from disbursement)
            $totalAmount = $creditValue + $totalInterestAmount;

            $now = Carbon::now($userTimezone ?: self::TIMEZONE);
            $createdAt = $params['created_at'] ?? $now;
            $updatedAt = $params['updated_at'] ?? $now;

            $creditData = [
                'client_id' => $params['client_id'],
                'phone' => $params['phone'],
                'guarantor_id' => $params['guarantor_id'] ?? null,
                'seller_id' => $params['seller_id'],
                'credit_value' => $params['credit_value'],
                'number_installments' => $params['number_installments'] ?? $params['installment_count'] ?? null,
                'payment_frequency' => $params['payment_frequency'],
                'total_interest' => $interestRate, // Keeping consistent with existing logic where total_interest stores the rate
                'total_amount' => $totalAmount,
                'remaining_amount' => $totalAmount,
                'first_quota_date' => $firstQuotaDate,
                'excluded_days' => isset($params['excluded_days']) ? json_encode($params['excluded_days']) : null,
                'micro_insurance_percentage' => $microInsurancePercentage,
                'micro_insurance_amount' => $microInsuranceAmount,
                'is_advance_payment' => $params['is_advance_payment'] ?? false,
                'status' => 'Vigente',
                'created_at' => $createdAt,
                'updated_at' => $updatedAt
            ];

            $credit = Credit::create($creditData);

            // Notificación si el crédito supera el límite configurado
            /*   $sellerConfig = \App\Models\SellerConfig::where('seller_id', $credit->seller_id)->first();
            $limit = $sellerConfig ? floatval($sellerConfig->notify_new_credit_amount_limit ?? 0) : 0;
            if ($limit > 0 && $credit->credit_value > $limit) {
                $user = $credit->seller->user;
                $message = 'Aviso: El crédito creado supera el límite configurado de $' . number_format($limit, 2) . '. Monto crédito: $' . number_format($credit->credit_value, 2) . '.';
                $link = '/dashboard/creditos';
                $data = [
                    'seller_id' => $credit->seller_id,
                    'date' => Carbon::now('America/Lima')->toDateString(),
                    'credit_value' => $credit->credit_value,
                    'limit' => $limit,
                ];
                if ($user) {
                    $user->notify(new \App\Notifications\GeneralNotification(
                        'Crédito creado supera el límite',
                        $message,
                        $link,
                        $data
                    ));
                }
                $admins = \App\Models\User::where('role_id', 1)->get();
                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\GeneralNotification(
                        'Crédito creado supera el límite',
                        'El vendedor ' . $user->name . ' ha creado un crédito que supera el límite configurado. Monto crédito: $' . number_format($credit->credit_value, 2) . '.',
                        $link,
                        $data
                    ));
                }
            } */

            $quotaAmount = $credit->total_amount / $credit->number_installments;

            $excludedDayNames = json_decode($credit->excluded_days, true) ?? [];

            $dayMap = [
                'Domingo' => Carbon::SUNDAY,
                'Lunes' => Carbon::MONDAY,
                'Martes' => Carbon::TUESDAY,
                'Miércoles' => Carbon::WEDNESDAY,
                'Jueves' => Carbon::THURSDAY,
                'Viernes' => Carbon::FRIDAY,
                'Sábado' => Carbon::SATURDAY
            ];

            $excludedDayNumbers = [];
            foreach ($excludedDayNames as $dayName) {
                if (isset($dayMap[$dayName])) {
                    $excludedDayNumbers[] = $dayMap[$dayName];
                }
            }

            $adjustForExcludedDays = function ($date) use ($excludedDayNumbers) {
                while (in_array($date->dayOfWeek, $excludedDayNumbers)) {
                    $date->addDay();
                }
                return $date;
            };

            $dueDate = $adjustForExcludedDays(Carbon::parse($credit->first_quota_date));


            for ($i = 1; $i <= $credit->number_installments; $i++) {

                Installment::create([
                    'credit_id' => $credit->id,
                    'quota_number' => $i,
                    'due_date' => $dueDate->format('Y-m-d'),
                    'quota_amount' => round($quotaAmount, 2),
                    'status' => 'Pendiente',
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt
                ]);

                if ($i < $credit->number_installments) {
                    switch ($credit->payment_frequency) {
                        case 'Diaria':
                            $dueDate->addDay();
                            break;
                        case 'Semanal':
                            $dueDate->addWeek();
                            break;
                        case 'Quincenal':
                            $dueDate->addDays(15);
                            break;
                        case 'Mensual':
                            $dueDate->addMonth();
                            break;
                        default:
                            $dueDate->addMonth();
                    }

                    // Ajustar la nueva fecha si cae en día excluido
                    $dueDate = $adjustForExcludedDays($dueDate);
                }
            }

            if ($request->has('images')) {
                $images = $request->input('images');

                // Generate description for images
                $creditDescription = "Crédito ID: {$credit->id} - Valor: $" . number_format($credit->credit_value, 2) . " - Creado: " . ($credit->created_at ? $credit->created_at->format('Y-m-d H:i') : now()->format('Y-m-d H:i'));

                foreach ($images as $index => $imageData) {
                    $imageFile = $request->file("images.{$index}.file");

                    $imagePath = Helper::uploadFile($imageFile, 'clients');

                    $imageRecord = [
                        'path' => $imagePath,
                        'type' => $imageData['type'],
                        'description' => $creditDescription,
                        'credit_id' => $credit->id,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt
                    ];

                    // Add GPS metadata if available
                    if (isset($imageData['latitude'])) {
                        $imageRecord['latitude'] = $imageData['latitude'];
                    }
                    if (isset($imageData['longitude'])) {
                        $imageRecord['longitude'] = $imageData['longitude'];
                    }
                    if (isset($imageData['accuracy'])) {
                        $imageRecord['accuracy'] = $imageData['accuracy'];
                    }
                    if (isset($imageData['address'])) {
                        $imageRecord['address'] = $imageData['address'];
                    }
                    if (isset($imageData['location_timestamp'])) {
                        try {
                            $imageRecord['location_timestamp'] = \Carbon\Carbon::parse($imageData['location_timestamp'])->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            \Log::warning("Invalid location_timestamp format: " . $imageData['location_timestamp']);
                            $imageRecord['location_timestamp'] = null;
                        }
                    }

                    $credit->client->images()->create($imageRecord);
                }
            }



            $response = $this->successResponse([
                'success' => true,
                'message' => 'Crédito creado con éxito',
                'data' => [
                    'credit' => $credit,
                    'first_quota_date' => $credit->first_quota_date,
                    'adjusted_first_date' => $dueDate->format('Y-m-d'),
                    'total_installments' => $credit->number_installments
                ]
            ]);

            // Record Geolocation History
            if ($request->has('latitude') && $request->has('longitude')) {
                $this->geolocationHistoryService->record(
                    $credit->client_id,
                    $request->input('latitude'),
                    $request->input('longitude'),
                    'credit_created',
                    'Creación de crédito',
                    $credit->id,
                    $request->input('address'),
                    $request->input('accuracy')
                );
            }

            // Actualizar liquidación inmediatamente para asegurar integridad
            try {
                $businessDate = $credit->created_at->setTimezone(self::TIMEZONE)->toDateString();
                $liquidationService = app(\App\Services\LiquidationService::class);
                $liquidationService->recalculateLiquidation($credit->seller_id, $businessDate);
                $liquidationService->recalculateNextLiquidations($credit->seller_id, $businessDate);
                \Log::info("Liquidación recalculada tras creación de crédito " . $credit->id);
            } catch (\Exception $e) {
                \Log::error("Error recalculando liquidación al crear crédito: " . $e->getMessage());
            }

            return $response;
        } catch (\Exception $e) {
            \Log::error("Error al crear crédito: " . $e->getMessage());
            /* \Log::error($e->getTraceAsString()); */
            return $this->errorResponse('Error al crear el crédito: ' . $e->getMessage(), 500);
        }
    }

    public function renew(Request $request)
    {
        try {
            // Validate required fields
            $request->validate([
                'old_credit_id' => 'required|exists:credits,id',
                'new_credit_value' => 'required|numeric|min:1',
                'phone' => 'required|string|min:7',
                'micro_insurance_percentage' => 'required|numeric',
            ], [
                'phone.required' => 'El teléfono es obligatorio',
                'phone.min' => 'El teléfono debe tener al menos 7 caracteres',
                'micro_insurance_percentage.required' => 'El porcentaje de microseguro es obligatorio',
                'micro_insurance_percentage.numeric' => 'El porcentaje de microseguro debe ser un número',
            ]);

            DB::beginTransaction();

            $params = $request->all();
            $createdAt = null;
            $updatedAt = null;
            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $createdAt = Carbon::now($params['timezone']);
                $updatedAt = Carbon::now($params['timezone']);
                unset($params['timezone']);
            } else {
                $createdAt = null;
                $updatedAt = null;
            }

            // 1. Buscar crédito anterior
            $oldCredit = Credit::findOrFail($request->old_credit_id);
            $pendingAmount = $oldCredit->pendingAmount();

            $firstQuotaDate = $request->input('first_installment_date');
            if (!$firstQuotaDate) {
                $today = now();
                $paymentFrequency = $request->input('payment_frequency', $oldCredit->payment_frequency);

                switch ($paymentFrequency) {
                    case 'Diaria':
                        $firstQuotaDate = $today->addDay()->format('Y-m-d');
                        break;
                    case 'Semanal':
                        $firstQuotaDate = $today->addWeek()->format('Y-m-d');
                        break;
                    case 'Quincenal':
                        $firstQuotaDate = $today->addDays(15)->format('Y-m-d');
                        break;
                    case 'Mensual':
                        $firstQuotaDate = $today->addMonth()->format('Y-m-d');
                        break;
                    default:
                        $firstQuotaDate = $today->addDay()->format('Y-m-d');
                }
            }

            // Calculate micro insurance amount
            $microInsurancePercentage = $request->input('micro_insurance_percentage', 0);
            $microInsuranceAmount = ($request->new_credit_value * $microInsurancePercentage) / 100;

            // Calculate total interest amount
            $interestRate = $request->input('interest_rate', $oldCredit->total_interest);
            $totalInterestAmount = ($request->new_credit_value * $interestRate) / 100;

            // Calculate total amount (Capital + Interest + Pending Amount)
            // Micro-insurance is NOT added to the total amount to pay
            $totalAmount = $request->new_credit_value + $totalInterestAmount + $pendingAmount;

            $newCredit = Credit::create([
                'client_id' => $oldCredit->client_id,
                'phone' => $request->input('phone'),
                'seller_id' => $oldCredit->seller_id,
                'credit_value' => $request->new_credit_value,
                'total_interest' => $interestRate,
                'total_amount' => $totalAmount,
                'remaining_amount' => $totalAmount,
                'number_installments' => $request->input('number_installments') ?? $request->input('installment_count', $oldCredit->number_installments),
                'payment_frequency' => $request->input('payment_frequency', $oldCredit->payment_frequency),
                'first_quota_date' => $firstQuotaDate,
                'previous_pending_amount' => $pendingAmount,
                'renewed_from_id' => $request->old_credit_id,
                'micro_insurance_percentage' => $microInsurancePercentage,
                'micro_insurance_amount' => $microInsuranceAmount,
                'excluded_days' => $request->input('excluded_days') ? json_encode($request->input('excluded_days')) : $oldCredit->excluded_days,
                'status' => 'Vigente',
                'created_at' => $createdAt,
                'updated_at' => $updatedAt
            ]);

            $quotaAmount = $newCredit->total_amount / $newCredit->number_installments;

            $excludedDayNames = json_decode($newCredit->excluded_days ?? '[]', true) ?? [];

            $dayMap = [
                'Domingo' => Carbon::SUNDAY,
                'Lunes' => Carbon::MONDAY,
                'Martes' => Carbon::TUESDAY,
                'Miércoles' => Carbon::WEDNESDAY,
                'Jueves' => Carbon::THURSDAY,
                'Viernes' => Carbon::FRIDAY,
                'Sábado' => Carbon::SATURDAY
            ];

            $excludedDayNumbers = [];
            foreach ($excludedDayNames as $dayName) {
                if (isset($dayMap[$dayName])) {
                    $excludedDayNumbers[] = $dayMap[$dayName];
                }
            }

            $adjustForExcludedDays = function ($date) use ($excludedDayNumbers) {
                while (in_array($date->dayOfWeek, $excludedDayNumbers)) {
                    $date->addDay();
                }
                return $date;
            };

            $dueDate = $adjustForExcludedDays(Carbon::parse($newCredit->first_quota_date));

            for ($i = 1; $i <= $newCredit->number_installments; $i++) {
                Installment::create([
                    'credit_id' => $newCredit->id,
                    'quota_number' => $i,
                    'due_date' => $dueDate->format('Y-m-d'),
                    'quota_amount' => round($quotaAmount, 2),
                    'status' => 'Pendiente',
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt
                ]);

                if ($i < $newCredit->number_installments) {
                    switch ($newCredit->payment_frequency) {
                        case 'Diaria':
                            $dueDate->addDay();
                            break;
                        case 'Semanal':
                            $dueDate->addWeek();
                            break;
                        case 'Quincenal':
                            $dueDate->addDays(15);
                            break;
                        case 'Mensual':
                            $dueDate->addMonth();
                            break;
                        default:
                            $dueDate->addMonth();
                    }
                    $dueDate = $adjustForExcludedDays($dueDate);
                }
            }

            // 3.1 Marcar cuotas pendientes del crédito anterior como pagadas
            Installment::where('credit_id', $oldCredit->id)
                ->where('status', 'Pendiente')
                ->update(['status' => 'Pagado']);

            // 3. Liquidar el crédito anterior
            $oldCredit->status = 'Renovado';
            $oldCredit->renewed_to_id = $newCredit->id;
            $oldCredit->is_renewed = true;
            $oldCredit->save();

            // Registrar pago de liquidación
            /*    Payment::create([
                'credit_id' => $oldCredit->id,
                'amount'    => $pendingAmount,
                'type'      => 'Liquidación por renovación',
                'payment_date' => now(),
                'status'    => 'Pagado',
            ]); */

            // 4. Registrar desembolso del nuevo crédito
            // Desembolso Neto = Nuevo Crédito - Saldo Pendiente - Microseguro
            $netDisbursement = $request->new_credit_value - $pendingAmount - $microInsuranceAmount;
            /*        Payment::create([
                'credit_id' => $newCredit->id,
                'amount'    => $netDisbursement,
                'type'      => 'Desembolso renovación',
                'payment_date' => now(),
                'status'    => 'Pagado',
            ]); */

            // 4.1 Process images for renewal
            if ($request->has('images')) {
                $images = $request->input('images');
                $creditDescription = "Renovación Crédito ID: {$newCredit->id} (Desde: {$oldCredit->id}) - Valor: $" . number_format($newCredit->credit_value, 2) . " - Creado: " . ($newCredit->created_at ? $newCredit->created_at->format('Y-m-d H:i') : now()->format('Y-m-d H:i'));

                foreach ($images as $index => $imageData) {
                    $imageFile = $request->file("images.{$index}.file");
                    $imagePath = Helper::uploadFile($imageFile, 'clients');

                    $imageRecord = [
                        'path' => $imagePath,
                        'type' => $imageData['type'],
                        'description' => $creditDescription,
                        'credit_id' => $newCredit->id,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt
                    ];

                    if (isset($imageData['latitude']))
                        $imageRecord['latitude'] = $imageData['latitude'];
                    if (isset($imageData['longitude']))
                        $imageRecord['longitude'] = $imageData['longitude'];
                    if (isset($imageData['accuracy']))
                        $imageRecord['accuracy'] = $imageData['accuracy'];
                    if (isset($imageData['address']))
                        $imageRecord['address'] = $imageData['address'];
                    if (isset($imageData['location_timestamp'])) {
                        try {
                            $imageRecord['location_timestamp'] = \Carbon\Carbon::parse($imageData['location_timestamp'])->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            $imageRecord['location_timestamp'] = null;
                        }
                    }

                    $newCredit->client->images()->create($imageRecord);
                }
            }

            \DB::commit();

            // 5. Retornar desglose
            return $this->successResponse([
                'success' => true,
                'monto_total_nuevo' => $request->new_credit_value,
                'saldo_pagado' => $pendingAmount,
                'desembolso_neto' => $netDisbursement,
                'credit' => $newCredit,
                'old_credit' => $oldCredit,
            ]);
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error("Error en renovación de crédito: " . $e->getMessage());
            return $this->errorResponse('Error en renovación de crédito: ' . $e->getMessage(), 500);
        }
    }

    public function updateCreditSchedule(int $creditId, string $newFirstQuotaDate, $timezone = null, $notes = null, $newStartDate = null)
    {
        try {
            DB::beginTransaction();

            $tz = $timezone ?: self::TIMEZONE;
            $credit = Credit::with(['installments'])->findOrFail($creditId);

            if ($newStartDate) {
                $credit->start_date = $newStartDate;
            }

            // Días excluidos del crédito
            $excludedDayNames = json_decode($credit->excluded_days ?? '[]', true) ?? [];
            $dayMap = [
                'Domingo' => Carbon::SUNDAY,
                'Lunes' => Carbon::MONDAY,
                'Martes' => Carbon::TUESDAY,
                'Miércoles' => Carbon::WEDNESDAY,
                'Jueves' => Carbon::THURSDAY,
                'Viernes' => Carbon::FRIDAY,
                'Sábado' => Carbon::SATURDAY
            ];
            $excludedDayNumbers = [];
            foreach ($excludedDayNames as $dayName) {
                if (isset($dayMap[$dayName])) {
                    $excludedDayNumbers[] = $dayMap[$dayName];
                }
            }

            $adjustForExcludedDays = function (Carbon $date) use ($excludedDayNumbers) {
                while (in_array($date->dayOfWeek, $excludedDayNumbers)) {
                    $date->addDay();
                }
                return $date;
            };

            // Inicial fecha de la primera cuota en la zona del usuario
            $dueDate = Carbon::parse($newFirstQuotaDate, $tz);
            $dueDate = $adjustForExcludedDays($dueDate);

            $oldValue = [
                'first_quota_date' => $credit->first_quota_date,
                'start_date' => $credit->start_date,
                'credit_value' => (float)$credit->credit_value,
                'number_installments' => (int)$credit->number_installments,
                'micro_insurance_percentage' => (float)$credit->micro_insurance_percentage,
                'payment_frequency' => $credit->payment_frequency
            ];

            // Ordenar cuotas por número de cuota y actualizar due_date secuencialmente
            $installments = $credit->installments->sortBy('quota_number');
            $affectedInstallments = [];

            foreach ($installments as $inst) {
                $inst->due_date = $dueDate->format('Y-m-d');
                $inst->save();

                $affectedInstallments[] = $inst->id;

                // Avanzar la fecha según la frecuencia del crédito
                switch ($credit->payment_frequency) {
                    case 'Diaria':
                        $dueDate->addDay();
                        break;
                    case 'Semanal':
                        $dueDate->addWeek();
                        break;
                    case 'Quincenal':
                        $dueDate->addDays(15);
                        break;
                    case 'Mensual':
                        $dueDate->addMonth();
                        break;
                    default:
                        $dueDate->addMonth();
                }

                // Ajustar si cae en día excluido
                $dueDate = $adjustForExcludedDays($dueDate);
            }

            // Actualizar primera cuota en el crédito
            $credit->first_quota_date = $newFirstQuotaDate;
            $credit->has_been_modified = true;
            $credit->modification_count = ($credit->modification_count ?? 0) + 1;
            $credit->last_modified_at = now();
            $credit->last_modified_by = Auth::id() ?? 1;
            $credit->save();

            // Registrar Auditoría
            CreditModification::create([
                'credit_id' => $credit->id,
                'user_id' => Auth::id() ?? 1,
                'modification_type' => 'initial_date',
                'old_value' => $oldValue,
                'new_value' => [
                    'first_quota_date' => $newFirstQuotaDate, 
                    'start_date' => $newStartDate ?? $credit->start_date
                ],
                'affected_installments' => $affectedInstallments,
                'notes' => $notes ?: 'Cambio de fecha inicial de cuotas',
                'ip_address' => request()->ip()
            ]);

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Fechas de cuotas actualizadas correctamente',
                'data' => [
                    'credit_id' => $credit->id,
                    'first_quota_date' => $credit->first_quota_date,
                    'installments_updated' => $installments->map(function ($i) {
                        return ['id' => $i->id, 'quota_number' => $i->quota_number, 'due_date' => $i->due_date];
                    })
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Error updateCreditSchedule ({$creditId}): " . $e->getMessage());
            return $this->errorResponse('Error al actualizar el calendario de cuotas: ' . $e->getMessage(), 500);
        }
    }

    public function updateCreditFrequency(
        int $creditId,
        string $newFrequency,
        ?string $newFirstQuotaDate = null,
        ?int $newInstallments = null,
        ?float $newInterestRate = null,
        ?float $newInsurancePercentage = null,
        $timezone = null,
        $notes = null,
        $newStartDate = null,
        ?float $newCreditValue = null,
        bool $recalculatePaid = false,
        PaymentService $paymentService = null
    ) {
        try {
            DB::beginTransaction();

            $tz = $timezone ?: self::TIMEZONE;
            $credit = Credit::with(['installments'])->findOrFail($creditId);

            // Días excluidos del crédito
            $excludedDayNames = json_decode($credit->excluded_days ?? '[]', true) ?? [];
            $dayMap = [
                'Domingo' => Carbon::SUNDAY,
                'Lunes' => Carbon::MONDAY,
                'Martes' => Carbon::TUESDAY,
                'Miércoles' => Carbon::WEDNESDAY,
                'Jueves' => Carbon::THURSDAY,
                'Viernes' => Carbon::FRIDAY,
                'Sábado' => Carbon::SATURDAY
            ];
            $excludedDayNumbers = [];
            foreach ($excludedDayNames as $dayName) {
                if (isset($dayMap[$dayName])) {
                    $excludedDayNumbers[] = $dayMap[$dayName];
                }
            }

            $adjustForExcludedDays = function (Carbon $date) use ($excludedDayNumbers) {
                while (in_array($date->dayOfWeek, $excludedDayNumbers)) {
                    $date->addDay();
                }
                return $date;
            };

            $oldTotalInstallments = $credit->number_installments;
            $newNumInstallments = $newInstallments ?? $credit->number_installments;
            $interestRate = $newInterestRate ?? $credit->total_interest;
            $insurancePercentage = $newInsurancePercentage ?? $credit->micro_insurance_percentage ?? 0;
            $creditValue = $newCreditValue ?? (float) $credit->credit_value;

            // 1. Identificar cuotas pagadas
            $currentInstallments = $credit->installments->sortBy('quota_number');
            $paidInstallments = $currentInstallments->filter(function ($i) {
                return in_array(strtolower($i->status), ['pagado', 'paid', 'pagada']);
            });

            $paidCount = $paidInstallments->count();
            $paidAmountSum = $paidInstallments->sum('quota_amount');

            if (!$recalculatePaid && $newNumInstallments < $paidCount) {
                throw new \Exception("El nuevo número de cuotas ($newNumInstallments) no puede ser menor a las ya pagadas ($paidCount).");
            }

            // 2. Recalcular montos financieros
            // El costo total para las cuotas incluye Capital + Interés
            $insuranceAmount = ($creditValue * $insurancePercentage) / 100;
            $newTotalCost = ($creditValue * (1 + ($interestRate / 100)));

            if ($recalculatePaid) {
                // Recalcular TODO desde la cuota 1
                $newQuotaAmount = $newNumInstallments > 0 ? round($newTotalCost / $newNumInstallments, 2) : 0;
            } else {
                // Solo recalcular pendientes, respetando lo ya pagado
                $remainingCost = $newTotalCost - $paidAmountSum;
                $pendingCount = $newNumInstallments - $paidCount;
                $newQuotaAmount = $pendingCount > 0 ? round($remainingCost / $pendingCount, 2) : 0;
            }
            $loopStart = 1;

            // 3. Determinar fecha de inicio para las pendientes
            $baseDateStr = $newFirstQuotaDate;
            if (!$baseDateStr) {
                $firstPending = $currentInstallments->whereNotIn('status', ['Pagado', 'Paid', 'Pagada'])->first();
                $baseDateStr = $firstPending ? $firstPending->due_date : $credit->first_quota_date;
            }
            $dueDate = Carbon::parse($baseDateStr, $tz);
            $dueDate = $adjustForExcludedDays($dueDate);

            $oldValue = [
                'payment_frequency' => $credit->payment_frequency,
                'number_installments' => $credit->number_installments,
                'total_interest' => $credit->total_interest,
                'micro_insurance_percentage' => $credit->micro_insurance_percentage,
                'micro_insurance_amount' => $credit->micro_insurance_amount,
                'credit_value' => $credit->credit_value,
                'start_date' => $credit->start_date,
                'first_quota_date' => $credit->first_quota_date,
                'quota_amount' => $currentInstallments->whereNotIn('status', ['Pagado', 'Paid', 'Pagada'])->first()?->quota_amount
            ];

            // 4. ALWAYS reset all payments to unapplied and installments to Pendiente BEFORE the loop
            // This ensures that any change in structure (frequency, amounts) is correctly reflected in the schedule update.

            // Un-apply existing payments
            $payments = \App\Models\Payment::where('credit_id', $credit->id)
                ->where('status', '!=', 'Anulado')
                ->get();

            foreach ($payments as $payment) {
                \App\Models\PaymentInstallment::where('payment_id', $payment->id)->delete();
                $payment->unapplied_amount = $payment->amount;
                $payment->save();
            }

            // Reset all installments paid_amount and status to ensure they are picked up for potential date updates
            \App\Models\Installment::where('credit_id', $credit->id)->update([
                'paid_amount' => 0,
                'status' => 'Pendiente'
            ]);

            // Refresh currentInstallments to reflect the reset status
            $currentInstallments = \App\Models\Installment::where('credit_id', $credit->id)->get();

            // 4b. Actualizar/Eliminar/Crear cuotas
            $affectedInstallments = [];

            // Eliminar excedentes si el número de cuotas disminuyó
            if ($newNumInstallments < $oldTotalInstallments) {
                Installment::where('credit_id', $credit->id)
                    ->where('quota_number', '>', $newNumInstallments)
                    ->whereNotIn('status', ['Pagado', 'Paid', 'Pagada'])
                    ->delete();
            }

            for ($i = 1; $i <= $newNumInstallments; $i++) {
                // Identificar si la cuota original estaba pagada
                $wasPaid = $paidInstallments->contains('quota_number', $i);

                $instData = [
                    'quota_amount' => ($wasPaid && !$recalculatePaid) 
                        ? $paidInstallments->where('quota_number', $i)->first()->quota_amount 
                        : $newQuotaAmount,
                    'due_date' => $dueDate->format('Y-m-d'),
                    'status' => 'Pendiente'
                ];

                $inst = Installment::updateOrCreate(
                    ['credit_id' => $credit->id, 'quota_number' => $i],
                    $instData
                );

                $affectedInstallments[] = $inst->id;

                // Avanzar fecha de referencia
                if (true) { // Siempre avanzar para mantener el nuevo cronograma
                    switch ($newFrequency) {
                        case 'Diaria':
                            $dueDate->addDay();
                            break;
                        case 'Semanal':
                            $dueDate->addWeek();
                            break;
                        case 'Quincenal':
                            $dueDate->addDays(15);
                            break;
                        case 'Mensual':
                            $dueDate->addMonth();
                            break;
                        default:
                            $dueDate->addMonth();
                    }
                    $dueDate = $adjustForExcludedDays($dueDate);
                }
            }

            // 4c. Re-apply all unapplied amounts to the NEW schedule
            $redistributionSummary = "";
            if ($paymentService || class_exists(\App\Services\PaymentService::class)) {
                $ps = $paymentService ?: app(\App\Services\PaymentService::class);
                $resp = $ps->reapplyPayments($credit->id);
                $resData = json_decode($resp->getContent(), true);
                if ($resData['success'] ?? false) {
                    $appTot = number_format($resData['applied_total'] ?? 0, 2);
                    $instCov = $resData['installments_covered'] ?? 0;
                    $remUn = number_format($resData['remaining_unapplied'] ?? 0, 2);
                    $redistributionSummary = " | Pagos redistribuidos: \${$appTot} cubriendo {$instCov} cuotas. Saldo disponible: \${$remUn}.";
                }
            }

            // 5. Actualizar cabecera del crédito
            $credit->payment_frequency = $newFrequency;
            if ($newFirstQuotaDate)
                $credit->first_quota_date = $newFirstQuotaDate;
            $credit->number_installments = $newNumInstallments;
            $credit->total_interest = $interestRate;
            $creditValue = $newCreditValue ?? (float) $credit->credit_value;
            $credit->micro_insurance_percentage = $insurancePercentage;
            $credit->micro_insurance_amount = ($creditValue * $insurancePercentage) / 100;
            $credit->total_amount = $newTotalCost;
            if ($newStartDate)
                $credit->start_date = $newStartDate;
            if ($newCreditValue)
                $credit->credit_value = $newCreditValue;
            $credit->remaining_amount = $credit->pendingAmount();

            // Auditoría
            $credit->has_been_modified = true;
            $credit->modification_count = ($credit->modification_count ?? 0) + 1;
            $credit->last_modified_at = now();
            $credit->last_modified_by = Auth::id() ?? 1;

            $credit->save();

            // Registrar movimiento en el flujo del crédito (Payment)
            // Solo si el crédito NO fue creado hoy. Si fue creado hoy, el reporte ajustará automáticamente el rubro "Nuevos Créditos".
            if ($newCreditValue && $newCreditValue != $oldValue['credit_value']) {
                $createdAt = Carbon::now($tz);
                $creditDate = Carbon::parse($credit->created_at)->setTimezone($tz)->format('Y-m-d');

                if ($creditDate !== $createdAt->format('Y-m-d')) {
                    $capDiff = $newCreditValue - $oldValue['credit_value'];

                    // Calcular impacto del seguro
                    $newInsPct = $newInsurance ?? $credit->micro_insurance_percentage;
                    $newInsAmount = ($newCreditValue * $newInsPct) / 100;
                    $oldInsAmount = $oldValue['micro_insurance_amount'];
                    $insDiff = $newInsAmount - $oldInsAmount;

                    // 1. Ajuste de Capital
                    if (abs($capDiff) > 0.001) {
                        $absCap = number_format(abs($capDiff), 2);
                        $creationDate = Carbon::parse($credit->created_at)->format('Y-m-d');
                        $oldValFmt = number_format($oldValue['credit_value'], 2);
                        $newValFmt = number_format($newCreditValue, 2);

                        $credit->load('seller');
                        $sellerUserId = $credit->seller->user_id ?? Auth::id();

                        if ($capDiff > 0) {
                            // Aumento de Capital -> Salida de Dinero (Gasto)
                            $desc = "AJUSTE CAPITAL CRÉDITO #{$credit->id} (Creado: {$creationDate}). Cambio: \${$oldValFmt} -> \${$newValFmt}. SALIDA DE CAJA.";
                            Expense::create([
                                'value' => abs($capDiff),
                                'description' => $desc,
                                'user_id' => $sellerUserId,
                                'created_at' => $createdAt,
                                'status' => 'Aprobado'
                            ]);
                        } else {
                            // Disminución de Capital -> Entrada de Dinero (Ingreso)
                            $desc = "AJUSTE CAPITAL CRÉDITO #{$credit->id} (Creado: {$creationDate}). Cambio: \${$oldValFmt} -> \${$newValFmt}. ENTRADA A CAJA.";
                            Income::create([
                                'value' => abs($capDiff),
                                'description' => $desc,
                                'user_id' => $sellerUserId,
                                'created_at' => $createdAt,
                                'status' => 'Aprobado'
                            ]);
                        }
                    }

                    // 2. Ajuste de Seguro (Póliza)
                    if (abs($insDiff) > 0.001) {
                        $absIns = number_format(abs($insDiff), 2);
                        $credit->load('seller');
                        $sellerUserId = $credit->seller->user_id ?? Auth::id();
                        $creationDate = Carbon::parse($credit->created_at)->format('Y-m-d');

                        if ($insDiff > 0) {
                            // Aumento de Seguro -> Cobro Adicional -> Entrada de Dinero (Ingreso)
                            $desc = "AJUSTE SEGURO CRÉDITO #{$credit->id}. Diferencia cobrada: \${$absIns}. ENTRADA A CAJA.";
                            Income::create([
                                'value' => abs($insDiff),
                                'description' => $desc,
                                'user_id' => $sellerUserId,
                                'created_at' => $createdAt,
                                'status' => 'Aprobado'
                            ]);
                        } else {
                            // Disminución de Seguro -> Devolución -> Salida de Dinero (Gasto)
                            $desc = "AJUSTE SEGURO CRÉDITO #{$credit->id}. Diferencia devuelta: \${$absIns}. SALIDA DE CAJA.";
                            Expense::create([
                                'value' => abs($insDiff),
                                'description' => $desc,
                                'user_id' => $sellerUserId,
                                'created_at' => $createdAt,
                                'status' => 'Aprobado'
                            ]);
                        }
                    }
                }
            }

            // Registrar Auditoría
            CreditModification::create([
                'credit_id' => $credit->id,
                'user_id' => Auth::id() ?? 1,
                'modification_type' => 'frequency',
                'old_value' => $oldValue,
                'new_value' => [
                    'payment_frequency' => $newFrequency,
                    'number_installments' => $newNumInstallments,
                    'total_interest' => $interestRate,
                    'micro_insurance_percentage' => $insurancePercentage,
                    'credit_value' => $creditValue,
                    'quota_amount' => $newQuotaAmount,
                    'start_date' => $newStartDate,
                    'first_quota_date' => $newFirstQuotaDate ?? $credit->first_quota_date
                ],
                'affected_installments' => $affectedInstallments,
                'notes' => ($notes ?: 'Modificación integral de condiciones financieras') . $redistributionSummary,
                'ip_address' => request()->ip()
            ]);

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Crédito actualizado correctamente con recalculo financiero',
                'data' => [
                    'credit_id' => $credit->id,
                    'new_quota_amount' => $newQuotaAmount,
                    'new_total_cost' => round($newTotalCost, 2)
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Error updateCreditFrequency ({$creditId}): " . $e->getMessage());
            return $this->errorResponse('Error al actualizar la frecuencia del crédito: ' . $e->getMessage(), 500);
        }
    }

    // ... (setCreditRenewalBlocked, etc remain unchanged)

    // Skipping to simulateScheduleChange replacement below the ellipses...
    // I will replace simulateScheduleChange separately if it is not contiguous or just replace both if I select properly.
    // The selection in the replace_file_content tool is contiguous for updateCreditFrequency only.
    // I will update simulateScheduleChange in a separate call or extend the range
    // Wait, updateCreditFrequency ends at 755. simulateScheduleChange starts at 1882.
    // I should do two separate replacements or one if they were close. They are far.
    // I'll do updateCreditFrequency first.


    public function setCreditRenewalBlocked(int $creditId, bool $blocked = true)
    {
        try {
            $credit = Credit::find($creditId);
            if (!$credit) {
                return $this->errorResponse('Crédito no encontrado', 404);
            }

            $credit->renewal_blocked = $blocked;
            $credit->save();

            return $this->successResponse([
                'success' => true,
                'message' => $blocked ? 'Crédito bloqueado para renovación' : 'Crédito desbloqueado para renovación',
                'data' => ['credit_id' => $credit->id, 'renewal_blocked' => (bool) $credit->renewal_blocked]
            ]);
        } catch (\Exception $e) {
            \Log::error("Error setCreditRenewalBlocked ({$creditId}): " . $e->getMessage());
            return $this->errorResponse('Error al actualizar bloqueo de renovación: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Simulate credit deletion impact
     */
    public function simulateDelete($creditId)
    {
        try {
            $credit = Credit::with(['payments', 'seller', 'client'])->find($creditId);
            if (!$credit) {
                return $this->errorResponse('Crédito no encontrado', 404);
            }

            // 1. Gather all direct impacts by date
            $deltasByDate = [];
            
            $tz = self::TIMEZONE;
            
            // Payments removal - use ONLY business_date to match calculateLiquidationMetrics
            $payments = $credit->payments()->whereNotNull('business_date')->get();
            foreach ($payments as $payment) {
                $date = $payment->business_date->format('Y-m-d');
                if (!isset($deltasByDate[$date])) {
                    $deltasByDate[$date] = [
                        'total_collected' => 0.0,
                        'new_credits' => 0.0,
                        'poliza' => 0.0,
                        'total_renewal_disbursed' => 0.0,
                        'total_pending_absorbed' => 0.0
                    ];
                }
                $deltasByDate[$date]['total_collected'] -= floatval($payment->amount);
            }
            
            // Credit creation removal - MUST use the same timezone-aware logic as calculateLiquidationMetrics
            $creationDate = $credit->created_at ? $credit->created_at->setTimezone($tz)->toDateString() : null;
            if ($creationDate) {
                if (!isset($deltasByDate[$creationDate])) {
                    $deltasByDate[$creationDate] = [
                        'total_collected' => 0.0,
                        'new_credits' => 0.0,
                        'poliza' => 0.0,
                        'total_renewal_disbursed' => 0.0,
                        'total_pending_absorbed' => 0.0
                    ];
                }

                if ($credit->renewed_from_id) {
                    // It was a renewal
                    $oldCredit = Credit::find($credit->renewed_from_id);
                    $pendingAmount = 0.0;
                    if ($oldCredit) {
                        $oldCreditTotal = (floatval($oldCredit->credit_value) * floatval($oldCredit->total_interest) / 100) + floatval($oldCredit->credit_value);
                        $oldCreditPaid = Payment::where('credit_id', $oldCredit->id)->sum('amount');
                        $pendingAmount = max(0.0, floatval($oldCreditTotal) - floatval($oldCreditPaid));
                    }
                    $netDisbursement = floatval($credit->credit_value) - $pendingAmount;

                    $deltasByDate[$creationDate]['total_renewal_disbursed'] -= $netDisbursement;
                    $deltasByDate[$creationDate]['total_pending_absorbed'] -= $pendingAmount;
                } else {
                    // Normal credit
                    $deltasByDate[$creationDate]['new_credits'] -= floatval($credit->credit_value);
                }

                // Poliza removal
                $polizaImpact = (floatval($credit->micro_insurance_percentage ?? 0) * floatval($credit->credit_value) / 100);
                $deltasByDate[$creationDate]['poliza'] -= $polizaImpact;
            }

            // DEBUG: Log before empty check
            \Log::info("simulateDelete BEFORE empty check for credit #{$creditId}", [
                'seller_id' => $credit->seller_id,
                'payment_count' => count($payments),
                'payments_data' => $payments->map(fn($p) => [
                    'id' => $p->id,
                    'business_date' => $p->business_date,
                    'payment_date' => $p->payment_date,
                    'created_at' => $p->created_at,
                    'amount' => $p->amount
                ])->toArray(),
                'creationDate' => $creationDate,
                'deltasByDate_keys' => array_keys($deltasByDate),
                'deltasByDate' => $deltasByDate
            ]);

            if (empty($deltasByDate)) {
                return $this->successResponse([
                    'success' => true,
                    'data' => [
                        'entity' => $credit,
                        'affected_liquidations' => []
                    ]
                ]);
            }

            // 2. Propagate changes through liquidations
            $tz = self::TIMEZONE;
            $today = Carbon::now($tz)->toDateString();
            $earliestDate = min(array_keys($deltasByDate));

            $liquidations = Liquidation::where('seller_id', $credit->seller_id)
                ->where('date', '>=', $earliestDate)
                ->orderBy('date', 'asc')
                ->get();
            
            // DEBUG: Log simulation data
            \Log::info("simulateDelete DEBUG for credit #{$creditId}", [
                'seller_id' => $credit->seller_id,
                'payment_count' => $credit->payments()->count(),
                'deltasByDate' => $deltasByDate,
                'earliestDate' => $earliestDate,
                'liquidations_found' => $liquidations->count()
            ]);

            // DEBUG: Log simulation data
            \Log::info("simulateDelete DEBUG for credit #{$creditId}", [
                'seller_id' => $credit->seller_id,
                'payment_count' => $credit->payments()->count(),
                'deltasByDate' => $deltasByDate,
                'earliestDate' => $earliestDate,
                'liquidations_found' => $liquidations->count()
            ]);

            // DEBUG: Log simulation data
            \Log::info("simulateDelete DEBUG for credit #{$creditId}", [
                'seller_id' => $credit->seller_id,
                'payment_count' => $credit->payments()->count(),
                'deltasByDate' => $deltasByDate,
                'earliestDate' => $earliestDate,
                'liquidations_found' => $liquidations->count()
            ]);

            // Ensure today is represented if we have a delta for it, even if no liquidation exists
            if (!isset($deltasByDate[$today]) && $credit->created_at->setTimezone($tz)->toDateString() === $today) {
                // Should already be in deltasByDate if it's creation date, but just in case
            }

            $liquidationDates = $liquidations->pluck('date')->map(fn($d) => $d->format('Y-m-d'))->toArray();
            
            // Re-build a list of dates to process. Force inclusion of today's date so "En curso" shows impact.
            $allDates = array_unique(array_merge($liquidationDates, array_keys($deltasByDate), [$today]));
            sort($allDates);

            $simulationData = [];
            $runningInitialCashDelta = 0.0;
            $liquidationService = app(\App\Services\LiquidationService::class);

            foreach ($allDates as $dateStr) {
                // Find liquidation by comparing formatted date strings
                $liquidation = $liquidations->first(function($liq) use ($dateStr) {
                    return $liq->date->format('Y-m-d') === $dateStr;
                });
                $isVirtual = false;

                if (!$liquidation) {
                    if ($dateStr !== $today)
                        continue; // Only "today" can be virtual for now

                    // Create a virtual liquidation for today
                    $dynamic = $liquidationService->getLiquidationData($credit->seller_id, $today, $credit->seller->user_id, $tz);
                    $liquidation = (object) [
                        'id' => null,
                        'date' => Carbon::parse($dateStr),
                        'total_collected' => $dynamic['total_collected'] ?? 0,
                        'real_to_deliver' => $dynamic['real_to_deliver'] ?? 0,
                        'initial_cash' => $dynamic['initial_cash'] ?? 0,
                        'new_credits' => $dynamic['new_credits'] ?? 0,
                        'poliza' => $dynamic['poliza'] ?? 0,
                        'shortage' => 0,
                        'surplus' => 0,
                        'renewal_disbursed_total' => $dynamic['total_renewal_disbursed'] ?? 0,
                        'total_pending_absorbed' => $dynamic['total_pending_absorbed'] ?? 0,
                        'base_delivered' => $dynamic['base_delivered'] ?? 0
                    ];
                    $isVirtual = true;
                }

                $initialCashDelta = $runningInitialCashDelta;

                $direct = $deltasByDate[$dateStr] ?? [
                    'total_collected' => 0.0,
                    'new_credits' => 0.0,
                    'poliza' => 0.0,
                    'total_renewal_disbursed' => 0.0,
                    'total_pending_absorbed' => 0.0
                ];

                $deltaRealToDeliver = $initialCashDelta
                    + (0.0 + $direct['total_collected'] + $direct['poliza'])
                    - (0.0 + $direct['new_credits'] + $direct['total_renewal_disbursed'] + 0.0);

                $newRealToDeliver = floatval($liquidation->real_to_deliver) + $deltaRealToDeliver;
                $difference = $newRealToDeliver - floatval($liquidation->base_delivered);
                $newShortage = max(0.0, -$difference);
                $newSurplus = max(0.0, $difference);

                $allChanges = [
                    'initial_cash' => floatval($liquidation->initial_cash) + $initialCashDelta,
                    'total_collected' => floatval($liquidation->total_collected) + $direct['total_collected'],
                    'new_credits' => floatval($liquidation->new_credits) + $direct['new_credits'],
                    'poliza' => floatval($liquidation->poliza) + $direct['poliza'],
                    'renewal_disbursed_total' => floatval($liquidation->renewal_disbursed_total) + $direct['total_renewal_disbursed'],
                    'total_pending_absorbed' => floatval($liquidation->total_pending_absorbed) + $direct['total_pending_absorbed'],
                    'real_to_deliver' => $newRealToDeliver,
                    'shortage' => $newShortage,
                    'surplus' => $newSurplus
                ];

                $finalChanges = [];
                if ($initialCashDelta != 0)
                    $finalChanges['initial_cash'] = $allChanges['initial_cash'];
                if ($direct['total_collected'] != 0)
                    $finalChanges['total_collected'] = $allChanges['total_collected'];
                if ($direct['new_credits'] != 0)
                    $finalChanges['new_credits'] = $allChanges['new_credits'];
                if ($direct['poliza'] != 0)
                    $finalChanges['poliza'] = $allChanges['poliza'];
                if ($direct['total_renewal_disbursed'] != 0)
                    $finalChanges['renewal_disbursed_total'] = $allChanges['renewal_disbursed_total'];
                if ($direct['total_pending_absorbed'] != 0)
                    $finalChanges['total_pending_absorbed'] = $allChanges['total_pending_absorbed'];

                $finalChanges['real_to_deliver'] = $allChanges['real_to_deliver'];
                $finalChanges['shortage'] = $allChanges['shortage'];
                $finalChanges['surplus'] = $allChanges['surplus'];

                $dailyBreakdown = [];
                if ($initialCashDelta != 0)
                    $dailyBreakdown[] = ['label' => 'Arrastre (Caja Ant.)', 'value' => $initialCashDelta];
                if ($direct['total_collected'] != 0)
                    $dailyBreakdown[] = ['label' => 'Cobros', 'value' => $direct['total_collected']];
                if ($direct['poliza'] != 0)
                    $dailyBreakdown[] = ['label' => 'Póliza', 'value' => $direct['poliza']];
                if ($direct['new_credits'] != 0)
                    $dailyBreakdown[] = ['label' => 'Crédito Nuevo', 'value' => -$direct['new_credits']];
                if ($direct['total_renewal_disbursed'] != 0)
                    $dailyBreakdown[] = ['label' => 'Renovación', 'value' => -$direct['total_renewal_disbursed']];

                $simulationData[] = [
                    'liquidation' => [
                        'id' => $liquidation->id,
                        'date' => $liquidation->date->format('Y-m-d'),
                        'total_collected' => floatval($liquidation->total_collected),
                        'real_to_deliver' => floatval($liquidation->real_to_deliver),
                        'initial_cash' => floatval($liquidation->initial_cash),
                        'new_credits' => floatval($liquidation->new_credits),
                        'poliza' => floatval($liquidation->poliza),
                        'shortage' => floatval($liquidation->shortage),
                        'surplus' => floatval($liquidation->surplus)
                    ],
                    'changes' => $finalChanges,
                    'impact_amount' => $deltaRealToDeliver,
                    'breakdown' => $dailyBreakdown,
                    'type' => $isVirtual ? 'Liquidación en curso' : 'Simulación de Eliminación'
                ];

                $runningInitialCashDelta = $deltaRealToDeliver;
            }

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'entity' => $credit,
                    'affected_liquidations' => $simulationData
                ]
            ]);

        } catch (\Throwable $e) {
            \Log::error("Error in CreditService::simulateDelete: " . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return $this->errorResponse('Error simulando eliminación: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Simulate credit edit impact
     */
    public function simulateEdit(int $creditId, array $newData)
    {
        try {
            $credit = Credit::with(['installments', 'seller', 'payments'])->findOrFail($creditId);
            $tz = self::TIMEZONE;

            $newCreditValue = isset($newData['credit_value']) ? (float) $newData['credit_value'] : (float) $credit->credit_value;
            $newInterestRate = isset($newData['interest_rate']) ? (float) $newData['interest_rate'] : (float) $credit->total_interest;
            $newInsurancePct = isset($newData['micro_insurance_percentage']) ? (float) $newData['micro_insurance_percentage'] : (float) $credit->micro_insurance_percentage;
            $newNumInstallments = isset($newData['number_installments']) ? (int) $newData['number_installments'] : (int) $credit->number_installments;
            $recalculatePaid = isset($newData['recalculate_paid']) ? (bool) $newData['recalculate_paid'] : false;

            $newTotalCost = ($newCreditValue * (1 + ($newInterestRate / 100)));
            $currentInstallments = $credit->installments->sortBy('quota_number');
            $paidInstallments = $currentInstallments->filter(function ($i) {
                return in_array(strtolower($i->status), ['pagado', 'paid', 'pagada']);
            });
            $paidCount = $paidInstallments->count();

            if ($recalculatePaid) {
                $newQuotaAmount = $newNumInstallments > 0 ? round($newTotalCost / $newNumInstallments, 2) : 0;
            } else {
                $paidAmountSum = $paidInstallments->sum('quota_amount');
                $remainingCost = $newTotalCost - $paidAmountSum;
                $pendingCount = $newNumInstallments - $paidCount;
                $newQuotaAmount = $pendingCount > 0 ? round($remainingCost / $pendingCount, 2) : 0;
            }

            $deltasByDate = [];

            // 1. Impact for Capital & Insurance changes (at creation date for simulation report)
            $creditDate = Carbon::parse($credit->created_at)->setTimezone($tz)->format('Y-m-d');

            // Capital delta
            $capDiff = $newCreditValue - (float) $credit->credit_value;
            if (abs($capDiff) > 0.001) {
                if (!isset($deltasByDate[$creditDate])) {
                    $deltasByDate[$creditDate] = ['total_collected' => 0, 'new_credits' => 0, 'poliza' => 0, 'expenses' => 0, 'incomes' => 0, 'total_renewal_disbursed' => 0];
                }

                if ($credit->renewed_from_id) {
                    $deltasByDate[$creditDate]['total_renewal_disbursed'] += $capDiff;
                } else {
                    $deltasByDate[$creditDate]['new_credits'] += $capDiff;
                }
            }

            // Insurance delta
            $newInsAmount = ($newCreditValue * $newInsurancePct) / 100;
            $oldInsAmount = (float) $credit->micro_insurance_amount;
            $insDiff = $newInsAmount - $oldInsAmount;
            if (abs($insDiff) > 0.001) {
                if (!isset($deltasByDate[$creditDate])) {
                    $deltasByDate[$creditDate] = ['total_collected' => 0, 'new_credits' => 0, 'poliza' => 0, 'expenses' => 0, 'incomes' => 0, 'total_renewal_disbursed' => 0];
                }
                $deltasByDate[$creditDate]['poliza'] += $insDiff;
            }

            // 2. Impact for paid installments recalculation (only if we assume payments would have changed)
            // But since physical cash doesn't change, we only paint affected liquidations based on Capital/Insurance.
            // However, to satisfy "No me pintas las liquidaciones afectadas por el cambio de cuotas pagadas",
            // we will show the deltas if they want to see the "corrected" history.
            if ($recalculatePaid && $paidCount > 0) {
                foreach ($paidInstallments as $inst) {
                    $paymentLink = PaymentInstallment::where('installment_id', $inst->id)->first();
                    if ($paymentLink) {
                        $payment = Payment::find($paymentLink->payment_id);
                        if ($payment && $payment->business_date) {
                            $date = $payment->business_date->format('Y-m-d');
                            if (!isset($deltasByDate[$date])) {
                                $deltasByDate[$date] = ['total_collected' => 0, 'new_credits' => 0, 'poliza' => 0, 'expenses' => 0, 'incomes' => 0, 'total_renewal_disbursed' => 0];
                            }
                            // This is a VIRTUAL delta to show how much "over/under" they would be.
                            // $deltasByDate[$date]['total_collected'] += ($newQuotaAmount - (float)$inst->quota_amount);
                        }
                    }
                }
            }

            if (empty($deltasByDate)) {
                return $this->successResponse(['success' => true, 'data' => ['affected_liquidations' => []]]);
            }

            // 3. Propagate changes
            $today = Carbon::now($tz)->toDateString();
            $earliestDate = min(array_keys($deltasByDate));

            $liquidations = Liquidation::where('seller_id', $credit->seller_id)
                ->where('date', '>=', $earliestDate)
                ->orderBy('date', 'asc')
                ->get();

            $liquidationDates = $liquidations->pluck('date')->map(fn($d) => $d->format('Y-m-d'))->toArray();
            
            // Force inclusion of today's date so "En curso" shows impact
            $allDates = array_unique(array_merge($liquidationDates, array_keys($deltasByDate), [$today]));
            sort($allDates);

            $simulationData = [];
            $runningInitialCashDelta = 0.0;
            $liquidationService = app(\App\Services\LiquidationService::class);

            foreach ($allDates as $dateStr) {
                $liquidation = $liquidations->first(function($liq) use ($dateStr) {
                    return $liq->date->format('Y-m-d') === $dateStr;
                });
                $isVirtual = false;

                if (!$liquidation) {
                    if ($dateStr !== $today)
                        continue;

                    $dynamic = $liquidationService->getLiquidationData($credit->seller_id, $today, $credit->seller->user_id, $tz);
                    $liquidation = (object) [
                        'id' => null,
                        'date' => Carbon::parse($dateStr),
                        'total_collected' => $dynamic['total_collected'] ?? 0,
                        'real_to_deliver' => $dynamic['real_to_deliver'] ?? 0,
                        'initial_cash' => $dynamic['initial_cash'] ?? 0,
                        'new_credits' => $dynamic['new_credits'] ?? 0,
                        'poliza' => $dynamic['poliza'] ?? 0,
                        'shortage' => 0,
                        'surplus' => 0,
                        'renewal_disbursed_total' => $dynamic['total_renewal_disbursed'] ?? 0,
                        'total_pending_absorbed' => $dynamic['total_pending_absorbed'] ?? 0,
                        'base_delivered' => $dynamic['base_delivered'] ?? 0
                    ];
                    $isVirtual = true;
                }

                $initialCashDelta = $runningInitialCashDelta;
                $direct = $deltasByDate[$dateStr] ?? ['total_collected' => 0, 'new_credits' => 0, 'poliza' => 0, 'expenses' => 0, 'incomes' => 0, 'total_renewal_disbursed' => 0];

                // deltaReal = initial + (incomes + collected + poliza) - (expenses + new_credits + renewal)
                $deltaRealToDeliver = $initialCashDelta
                    + (($direct['incomes'] ?? 0) + $direct['total_collected'] + $direct['poliza'])
                    - (($direct['expenses'] ?? 0) + $direct['new_credits'] + $direct['total_renewal_disbursed']);

                $finalChanges = [
                    'real_to_deliver' => floatval($liquidation->real_to_deliver) + $deltaRealToDeliver,
                    'initial_cash' => floatval($liquidation->initial_cash) + $initialCashDelta,
                ];

                if ($direct['total_collected'] != 0)
                    $finalChanges['total_collected'] = floatval($liquidation->total_collected) + $direct['total_collected'];
                if ($direct['new_credits'] != 0)
                    $finalChanges['new_credits'] = floatval($liquidation->new_credits) + $direct['new_credits'];
                if ($direct['poliza'] != 0)
                    $finalChanges['poliza'] = floatval($liquidation->poliza) + $direct['poliza'];
                if ($direct['total_renewal_disbursed'] != 0)
                    $finalChanges['renewal_disbursed_total'] = floatval($liquidation->renewal_disbursed_total) + $direct['total_renewal_disbursed'];

                $dailyBreakdown = [];
                if ($initialCashDelta != 0)
                    $dailyBreakdown[] = ['label' => 'Arrastre', 'value' => $initialCashDelta];
                if ($direct['total_collected'] != 0)
                    $dailyBreakdown[] = ['label' => 'Ajuste Cobros', 'value' => $direct['total_collected']];
                if ($direct['new_credits'] != 0)
                    $dailyBreakdown[] = ['label' => 'Ajuste Capital', 'value' => -$direct['new_credits']];
                if ($direct['poliza'] != 0)
                    $dailyBreakdown[] = ['label' => 'Ajuste Póliza', 'value' => $direct['poliza']];
                if ($direct['total_renewal_disbursed'] != 0)
                    $dailyBreakdown[] = ['label' => 'Ajuste Renovación', 'value' => -$direct['total_renewal_disbursed']];

                $simulationData[] = [
                    'liquidation' => [
                        'id' => $liquidation->id,
                        'date' => $dateStr,
                        'real_to_deliver' => floatval($liquidation->real_to_deliver),
                        'initial_cash' => floatval($liquidation->initial_cash),
                    ],
                    'changes' => $finalChanges,
                    'impact_amount' => $deltaRealToDeliver,
                    'breakdown' => $dailyBreakdown,
                    'type' => $isVirtual ? 'Liquidación en curso' : 'Simulación de Edición'
                ];

                $runningInitialCashDelta = $deltaRealToDeliver;
            }

            $newFrequency = $newData['frequency'] ?? ($newData['payment_frequency'] ?? $credit->payment_frequency);
            $newFirstDate = isset($newData['first_quota_date']) ? $newData['first_quota_date'] : $credit->first_quota_date;

            // Calculate total money actually paid (applied + unapplied)
            $totalPaidAmount = Payment::where('credit_id', $creditId)
                ->where('status', '!=', 'Anulado')
                ->sum('amount');

            $remainingSimulationPaid = $totalPaidAmount;

            // Re-trigger the logic to determine start date
            $baseDateStr = $newFirstDate;
            if (!$baseDateStr) {
                $firstPending = $currentInstallments->whereNotIn('status', ['Pagado', 'Paid', 'Pagada'])->first();
                $baseDateStr = $firstPending ? $firstPending->due_date : $credit->first_quota_date;
            }
            $dueDate = Carbon::parse($baseDateStr, $tz);

            // Excluded days helper
            $excludedDayNames = json_decode($credit->excluded_days ?? '[]', true) ?? [];
            $dayMap = [
                'Domingo' => Carbon::SUNDAY,
                'Lunes' => Carbon::MONDAY,
                'Martes' => Carbon::TUESDAY,
                'Miércoles' => Carbon::WEDNESDAY,
                'Jueves' => Carbon::THURSDAY,
                'Viernes' => Carbon::FRIDAY,
                'Sábado' => Carbon::SATURDAY
            ];
            $excludedDayNumbers = [];
            foreach ($excludedDayNames as $dayName)
                if (isset($dayMap[$dayName]))
                    $excludedDayNumbers[] = $dayMap[$dayName];

            $adjustDate = function (Carbon $date) use ($excludedDayNumbers) {
                while (in_array($date->dayOfWeek, $excludedDayNumbers))
                    $date->addDay();
                return $date;
            };

            $dueDate = $adjustDate($dueDate);

            for ($i = 1; $i <= $newNumInstallments; $i++) {
                $status = 'Pendiente';
                $installmentAmount = $newQuotaAmount;

                if ($recalculatePaid) {
                    if ($remainingSimulationPaid >= $installmentAmount - 0.001) {
                        $status = 'Pagado';
                        $remainingSimulationPaid -= $installmentAmount;
                    } elseif ($remainingSimulationPaid > 0.001) {
                        $status = 'Parcial';
                        $remainingSimulationPaid = 0;
                    }
                } else {
                    $existing = $currentInstallments->where('quota_number', $i)->first();
                    $originallyPaid = $existing && in_array(strtolower($existing->status), ['pagado', 'paid', 'pagada']);
                    if ($originallyPaid) {
                        $status = 'Pagado';
                        $installmentAmount = (float) $existing->quota_amount;
                    }
                }

                $projectedInstallments[] = [
                    'quota_number' => $i,
                    'due_date' => $dueDate->format('Y-m-d'),
                    'quota_amount' => (float) $installmentAmount,
                    'status' => $status
                ];

                if ($recalculatePaid || !$originallyPaid) {
                    switch ($newFrequency) {
                        case 'Diaria':
                            $dueDate->addDay();
                            break;
                        case 'Semanal':
                            $dueDate->addWeek();
                            break;
                        case 'Quincenal':
                            $dueDate->addDays(15);
                            break;
                        case 'Mensual':
                            $dueDate->addMonth();
                            break;
                        default:
                            $dueDate->addMonth();
                    }
                    $dueDate = $adjustDate($dueDate);
                }
            }

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'credit_id' => $creditId,
                    'new_quota_amount' => $newQuotaAmount,
                    'affected_liquidations' => $simulationData,
                    'projected_installments' => $projectedInstallments
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error("Error simulateEdit: " . $e->getMessage());
            return $this->errorResponse('Error en la simulación: ' . $e->getMessage(), 500);
        }
    }



    public function index(string $search, int $perPage)
    {
        try {
            $user = Auth::user();
            $seller = $user->seller;

            $creditsQuery = Credit::with(['client', 'route'])
                ->where(function ($query) use ($search) {
                    $query->whereHas('client', function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('dni', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                });

            if ($user->role_id == 5 && $seller) {
                $creditsQuery->whereHas('client', function ($query) use ($seller) {
                    $query->where('seller_id', $seller->id);
                });
            }

            $credits = $creditsQuery->paginate($perPage);

            return $this->successResponse([
                'success' => true,
                'data' => $credits,
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener los créditos');
        }
    }

    private GeolocationHistoryService $geolocationHistoryService;

    public function __construct(GeolocationHistoryService $geolocationHistoryService)
    {
        $this->geolocationHistoryService = $geolocationHistoryService;
    }
    public function show($creditId)
    {
        try {
            $credit = Credit::with(['client', 'guarantor', 'seller', 'installments', 'payments'])->find($creditId);

            if (!$credit) {
                return $this->errorResponse('El crédito no existe.', 404);
            }

            // Calcular estadísticas de cuotas
            $installments = $credit->installments ?? collect();
            $paidInstallments = $installments->where('status', 'Pagado');
            $pendingInstallments = $installments->where('status', '!=', 'Pagado');
            $today = now()->format('Y-m-d');
            $overdueInstallments = $pendingInstallments->filter(function ($inst) use ($today) {
                return $inst->due_date < $today;
            });

            // Calcular cuotas adelantadas (pagadas antes de su fecha de vencimiento)
            $advancedInstallments = $paidInstallments->filter(function ($inst) {
                if (!$inst->paid_at)
                    return false;
                $paidDate = \Carbon\Carbon::parse($inst->paid_at)->format('Y-m-d');
                return $paidDate < $inst->due_date;
            });

            // Contar abonos (pagos parciales)
            $partialPaymentsCount = $credit->payments->where('status', 'Abonado')->count();

            // Calcular montos
            $totalPaid = $credit->payments->where('status', 'Pagado')->sum('amount') ?? 0;
            $totalPartial = $credit->payments->where('status', 'Abonado')->sum('amount') ?? 0;

            $totalInterest = ($credit->credit_value * $credit->total_interest) / 100;
            $totalAmount = $credit->credit_value + $totalInterest;
            $installmentAmount = $credit->number_installments > 0
                ? $totalAmount / $credit->number_installments
                : 0;

            // Fecha de última cuota (fecha límite)
            $lastInstallment = $installments->sortByDesc('due_date')->first();
            $endDate = $lastInstallment ? $lastInstallment->due_date : null;

            // Agregar información adicional al crédito
            $creditData = $credit->toArray();
            $creditData['end_date'] = $endDate;
            $creditData['total_amount'] = round($totalAmount, 2);
            $creditData['installment_amount'] = round($installmentAmount, 2);
            $creditData['total_paid'] = round($totalPaid, 2);
            $creditData['total_partial'] = round($totalPartial, 2);
            // Calcular pendiente por pagar (Total a pagar - Total pagado - Total abonado)
            $remainingAmount = $totalAmount - $totalPaid - $totalPartial;
            $creditData['remaining_amount'] = round(max($remainingAmount, 0), 2);
            $creditData['paid_installments_count'] = $paidInstallments->count();
            // Cuotas pendientes = solo las futuras (no incluye las atrasadas)
            $creditData['pending_installments_count'] = $pendingInstallments->count() - $overdueInstallments->count();
            $creditData['overdue_installments_count'] = $overdueInstallments->count();
            $creditData['advanced_installments_count'] = $advancedInstallments->count();
            $creditData['partial_payments_count'] = $partialPaymentsCount;

            return $this->successResponse([
                'success' => true,
                'data' => $creditData
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener el crédito');
        }
    }

    public function update(CreditRequest $request, $creditId)
    {
        try {
            $credit = Credit::find($creditId);

            if (!$credit) {
                return $this->errorResponse('El crédito no existe.', 404);
            }

            $params = $request->validated();
            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['updated_at'] = Carbon::now($params['timezone']);
                unset($params['timezone']);
            }
            $credit->update($params);

            return $this->successResponse([
                'success' => true,
                'message' => 'Crédito actualizado correctamente',
                'data' => $credit
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al actualizar el crédito');
        }
    }

    public function delete($creditId, $password = null)
    {
        try {
            $user = Auth::user();
            
            // Only verify password if provided (for Cash Adjustment context)
            if ($password !== null && !Hash::check($password, $user->password)) {
                return $this->errorResponse('Contraseña incorrecta', 401);
            }

            $credit = Credit::with(['payments', 'installments', 'seller', 'client'])->find($creditId);

            if (!$credit) {
                return $this->errorResponse('Crédito no encontrado', 404);
            }

            // ===== DATE VALIDATION START =====
            // Check if credit is from today or requires special permissions
            $timezone = 'America/Lima';
            $today = Carbon::now($timezone)->startOfDay();
            $creditDate = Carbon::parse($credit->created_at)->setTimezone($timezone)->startOfDay();
            
            $isToday = $creditDate->equalTo($today);
            $hasAdjustmentPermission = $user->can('ajustar_caja') || $user->role_id === 1;
            
            // ===== PAYMENT AND LIQUIDATION VALIDATION =====
            $hasPayments = $credit->payments()->where('status', '!=', 'Anulado')->exists();
            $totalPaid = $credit->payments()->where('status', '!=', 'Anulado')->sum('amount');
            $paymentCount = $credit->payments()->where('status', '!=', 'Anulado')->count();
            $pendingInstallments = $credit->installments()->where('status', 'Pendiente')->count();

            // Get associated liquidation
            $liquidation = Liquidation::where('seller_id', $credit->seller_id)
                ->whereDate('date', $creditDate->format('Y-m-d'))
                ->first();

            // Log warning for critical deletions
            if ($hasPayments && $hasAdjustmentPermission) {
                \Log::warning('Usuario con permisos eliminando crédito con pagos', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'credit_id' => $credit->id,
                    'credit_date' => $creditDate->format('Y-m-d'),
                    'credit_amount' => $credit->credit_value,
                    'total_paid' => $totalPaid,
                    'payment_count' => $paymentCount,
                    'liquidation_id' => $liquidation ? $liquidation->id : null,
                    'timestamp' => now()
                ]);
            }
            
            // If credit is from a previous day and user doesn't have adjustment permission
            if (!$isToday && !$hasAdjustmentPermission) {
                $formattedDate = $creditDate->format('d/m/Y');
                $formattedAmount = number_format($credit->credit_value, 2);
                
                $liquidationInfo = $liquidation 
                    ? "Liquidación #{$liquidation->id} - Estado: {$liquidation->status}" 
                    : "Sin liquidación asociada";
                
                $paymentsInfo = $hasPayments 
                    ? "Pagos realizados: {$paymentCount} - Total abonado: $" . number_format($totalPaid, 2)
                    : "Sin pagos realizados";
                
                return $this->errorResponse([
                    "No se puede eliminar el crédito porque es de un día anterior.",
                    "Fecha del crédito: {$formattedDate}",
                    "Monto del crédito: $" . number_format($credit->credit_value, 2),
                    $liquidationInfo,
                    $paymentsInfo,
                    "Para eliminar este crédito, debe usar la sección 'Ajuste de Caja' o contactar al administrador del sistema."
                ], 403);
            }
            // ===== DATE VALIDATION END =====

            // We need to simulate the impact to know which liquidations to recalculate
            $simulation = $this->simulateDelete($creditId);
            $simulationContent = json_decode($simulation->getContent(), true);
            $affectedLiquidationsData = $simulationContent['data']['affected_liquidations'] ?? [];

            DB::beginTransaction();

            // 1. Delete payments (and their relations like payment_installments)
            $paymentIds = $credit->payments->pluck('id');
            // Use Eloquent instead of DB::table to enable SoftDeletes
            \App\Models\PaymentInstallment::whereIn('payment_id', $paymentIds)->delete();
            // Delete Payments
            Payment::where('credit_id', $creditId)->delete();
            
            // 2. Delete Credit and Installments (IMPORTANT: Do this BEFORE recalculating liquidations)
            $credit->installments()->delete();
            $credit->delete();

            // 3. Recalculate Liquidations
            $liquidationService = app(\App\Services\LiquidationService::class);
            $earliestDate = !empty($affectedLiquidationsData)
                ? min(array_map(fn($l) => $l['liquidation']['date'], $affectedLiquidationsData))
                : null;

            if ($earliestDate) {
                // Ensure the earliest liquidation exists for audit
                $mainLiquidation = $liquidationService->getOrCreateLiquidation($credit->seller_id, $earliestDate);
                $oldRealToDeliver = $mainLiquidation ? floatval($mainLiquidation->real_to_deliver) : 0;

                // Recalculate the first affected one
                $liquidationService->recalculateLiquidation($credit->seller_id, $earliestDate);
                // Cascade to all future ones
                $liquidationService->recalculateNextLiquidations($credit->seller_id, $earliestDate);

                // Record Audit
                // Re-fetch to get new value
                $updatedLiquidation = Liquidation::where('seller_id', $credit->seller_id)
                    ->where('date', $earliestDate)
                    ->first();
                $newRealToDeliver = $updatedLiquidation ? floatval($updatedLiquidation->real_to_deliver) : 0;

                if ($updatedLiquidation) {
                    \App\Models\LiquidationAudit::create([
                        'liquidation_id' => $updatedLiquidation->id,
                        'user_id' => $user->id,
                        'action' => 'deleted_credit',
                        'changes' => [
                            'description' => "Eliminación de Crédito #{$credit->id} - Cliente: {$credit->client->name} - Monto: {$credit->credit_value}",
                            'credit_id' => $credit->id,
                            'client' => $credit->client->name,
                            'amount' => floatval($credit->credit_value),
                            'old_real_to_deliver' => $oldRealToDeliver,
                            'new_real_to_deliver' => $newRealToDeliver,
                            'impact' => $newRealToDeliver - $oldRealToDeliver
                        ]
                    ]);
                }
            }

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Crédito eliminado y liquidaciones actualizadas correctamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Error al eliminar crédito: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Validate if a credit can be deleted and return detailed information
     * This is used by frontend to show confirmation dialog
     */
    public function validateDeletion($creditId)
    {
        try {
            $user = Auth::user();
            $credit = Credit::with(['payments', 'installments', 'seller', 'client'])->find($creditId);

            if (!$credit) {
                return $this->errorResponse('Crédito no encontrado', 404);
            }

            $timezone = 'America/Lima';
            $today = Carbon::now($timezone)->startOfDay();
            $creditDate = Carbon::parse($credit->created_at)->setTimezone($timezone)->startOfDay();
            
            $isToday = $creditDate->equalTo($today);
            $hasAdjustmentPermission = $user->can('ajustar_caja') || $user->role_id === 1;
            
            $hasPayments = $credit->payments()->where('status', '!=', 'Anulado')->exists();
            $totalPaid = $credit->payments()->where('status', '!=', 'Anulado')->sum('amount');
            $paymentCount = $credit->payments()->where('status', '!=', 'Anulado')->count();
            $pendingInstallments = $credit->installments()->where('status', 'Pendiente')->count();
            
            $liquidation = Liquidation::where('seller_id', $credit->seller_id)
                ->whereDate('date', $creditDate->format('Y-m-d'))
                ->first();

            $canDelete = $isToday || $hasAdjustmentPermission;
            $requiresConfirmation = $hasPayments || !$isToday;
            
            $warnings = [];
            if (!$isToday) {
                $warnings[] = "Este crédito es de un día anterior y afectará liquidaciones históricas.";
            }
            if ($hasPayments) {
                $warnings[] = "Este crédito tiene pagos realizados que serán eliminados.";
            }
            if ($liquidation && $liquidation->status === 'cerrada') {
                $warnings[] = "Este crédito pertenece a una liquidación cerrada.";
            }
            
            return $this->successResponse([
                'can_delete' => $canDelete,
                'requires_confirmation' => $requiresConfirmation,
                'credit_details' => [
                    'id' => $credit->id,
                    'date' => $creditDate->format('d/m/Y'),
                    'amount' => floatval($credit->credit_value),
                    'total_amount' => floatval($credit->total_amount),
                    'client_name' => $credit->client->name,
                    'is_today' => $isToday,
                ],
                'payment_details' => [
                    'has_payments' => $hasPayments,
                    'total_paid' => $totalPaid,
                    'payment_count' => $paymentCount,
                    'pending_installments' => $pendingInstallments,
                ],
                'liquidation_details' => $liquidation ? [
                    'id' => $liquidation->id,
                    'date' => $liquidation->date,
                    'status' => $liquidation->status,
                ] : null,
                'warnings' => $warnings,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Error al validar eliminación: ' . $e->getMessage(), 500);
        }
    }


    public function toggleCreditStatus($creditId, $status)
    {
        try {
            $credit = Credit::find($creditId);

            if (!$credit) {
                return $this->errorResponse('Crédito no encontrado', 404);
            }

            if ($status === 'uncollectible' && $credit->status === 'Vigente') {
                // Cambiar a Cartera Irrecuperable
                $credit->status = 'Cartera Irrecuperable';
            } elseif ($status === 'vigente' && $credit->status === 'Cartera Irrecuperable') {
                $credit->status = 'Vigente';
            } else {
                return $this->errorResponse('Estado no válido o no se puede cambiar el estado del crédito', 400);
            }

            $credit->save();

            return $this->successResponse([
                'success' => true,
                'message' => 'Estado del crédito actualizado con éxito',
                'data' => $credit
            ]);
        } catch (\Exception $e) {
            \Log::error("Error al actualizar el estado del crédito: " . $e->getMessage());
            return $this->errorResponse('Error al actualizar el estado del crédito', 500);
        }
    }

    public function toggleCreditsStatusMassively(array $creditIds, $status)
    {
        try {
            $validStatuses = ['uncollectible', 'vigente'];
            if (!in_array($status, $validStatuses)) {
                return $this->errorResponse('Estado no válido', 400);
            }

            $credits = Credit::whereIn('id', $creditIds)->get();

            if ($credits->isEmpty()) {
                return $this->errorResponse('No se encontraron créditos con los IDs proporcionados', 404);
            }

            $updatedCredits = [];
            foreach ($credits as $credit) {
                if ($status === 'uncollectible' && $credit->status === 'Vigente') {
                    $credit->status = 'Cartera Irrecuperable';
                } elseif ($status === 'vigente' && $credit->status === 'Cartera Irrecuperable') {
                    $credit->status = 'Vigente';
                } else {
                    continue;
                }

                $credit->save();
                $updatedCredits[] = $credit;
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Estados de los créditos actualizados masivamente con éxito',
                'data' => $updatedCredits
            ]);
        } catch (\Exception $e) {
            \Log::error("Error al actualizar los estados de los créditos masivamente: " . $e->getMessage());
            return $this->errorResponse('Error al actualizar los estados de los créditos masivamente', 500);
        }
    }

    public function unifyCredits(Request $request)
    {
        try {
            DB::beginTransaction();

            $params = $request->all();
            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $createdAt = Carbon::now($params['timezone']);
                $updatedAt = Carbon::now($params['timezone']);
                unset($params['timezone']);
            } else {
                $createdAt = null;
                $updatedAt = null;
            }

            // 1. Obtener los créditos a unificar
            $creditIds = $request->input('credit_ids'); // array de IDs
            $credits = Credit::whereIn('id', $creditIds)->get();

            if ($credits->count() < 2) {
                return $this->errorResponse('Debes seleccionar al menos dos créditos para unificar.', 400);
            }

            // 2. Crear el nuevo crédito unificado
            $params = $request->all();

            $creditValue = floatval($params['credit_value'] ?? 0);
            $interestRate = floatval($params['interest_rate'] ?? 0);
            $totalInterestAmount = ($creditValue * $interestRate) / 100;
            $totalAmount = $creditValue + $totalInterestAmount;

            $microInsurancePercentage = floatval($params['micro_insurance_percentage'] ?? 0);
            $microInsuranceAmount = ($creditValue * $microInsurancePercentage) / 100;

            // Calcular fecha de primera cuota si no se proporciona
            $firstQuotaDate = $params['first_quota_date'] ?? null;
            if (!$firstQuotaDate) {
                $today = now();
                switch ($params['payment_frequency']) {
                    case 'Diaria':
                        $firstQuotaDate = $today->addDay()->format('Y-m-d');
                        break;
                    case 'Semanal':
                        $firstQuotaDate = $today->addWeek()->format('Y-m-d');
                        break;
                    case 'Quincenal':
                        $firstQuotaDate = $today->addDays(15)->format('Y-m-d');
                        break;
                    case 'Mensual':
                        $firstQuotaDate = $today->addMonth()->format('Y-m-d');
                        break;
                    default:
                        $firstQuotaDate = $today->addDay()->format('Y-m-d');
                }
            }

            $newCredit = Credit::create([
                'client_id' => $params['client_id'],
                'seller_id' => $params['seller_id'],
                'guarantor_id' => $params['guarantor_id'] ?? null,
                'credit_value' => $creditValue,
                'total_interest' => $interestRate,
                'total_amount' => $totalAmount,
                'remaining_amount' => $totalAmount,
                'number_installments' => $params['number_installments'] ?? $params['installment_count'] ?? null,
                'payment_frequency' => $params['payment_frequency'],
                'excluded_days' => json_encode($params['excluded_days'] ?? []),
                'micro_insurance_percentage' => $microInsurancePercentage,
                'micro_insurance_amount' => $microInsuranceAmount,
                'first_quota_date' => $firstQuotaDate,
                'status' => 'Vigente',
                'unification_reason' => $params['description'] ?? null,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt
            ]);

            // 3. Generar cuotas para el nuevo crédito
            $quotaAmount = $newCredit->total_amount / $newCredit->number_installments;
            $this->generateInstallments(
                $newCredit,
                $quotaAmount,
                $newCredit->first_quota_date,
                $newCredit->payment_frequency,
                $newCredit->number_installments,
                $createdAt,
                $updatedAt
            );

            // 4. Actualizar los créditos originales
            foreach ($credits as $credit) {
                $credit->status = 'Unificado';
                $credit->unified_to_id = $newCredit->id;
                $credit->save();
            }

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Créditos unificados correctamente',
                'new_credit' => $newCredit,
                'unified_credits' => $credits,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Error al unificar créditos: " . $e->getMessage());
            return $this->errorResponse('Error al unificar créditos: ' . $e->getMessage(), 500);
        }
    }

    protected function generateInstallments(Credit $credit, float $quotaAmount, string $firstQuotaDate, string $paymentFrequency, int $numberInstallments, $createdAt = null, $updatedAt = null)
    {
        try {
            // Obtener días excluidos del crédito
            $excludedDayNames = json_decode($credit->excluded_days ?? '[]', true) ?? [];
            $dayMap = [
                'Domingo' => Carbon::SUNDAY,
                'Lunes' => Carbon::MONDAY,
                'Martes' => Carbon::TUESDAY,
                'Miércoles' => Carbon::WEDNESDAY,
                'Jueves' => Carbon::THURSDAY,
                'Viernes' => Carbon::FRIDAY,
                'Sábado' => Carbon::SATURDAY
            ];
            $excludedDayNumbers = [];
            foreach ($excludedDayNames as $dayName) {
                if (isset($dayMap[$dayName])) {
                    $excludedDayNumbers[] = $dayMap[$dayName];
                }
            }
            $adjustForExcludedDays = function ($date) use ($excludedDayNumbers) {
                while (in_array($date->dayOfWeek, $excludedDayNumbers)) {
                    $date->addDay();
                }
                return $date;
            };

            $dueDate = $adjustForExcludedDays(Carbon::parse($firstQuotaDate));

            for ($i = 1; $i <= $numberInstallments; $i++) {
                Installment::create([
                    'credit_id' => $credit->id,
                    'quota_number' => $i,
                    'due_date' => $dueDate->format('Y-m-d'),
                    'quota_amount' => round($quotaAmount, 2),
                    'status' => 'Pendiente',
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt
                ]);

                if ($i < $numberInstallments) {
                    switch ($paymentFrequency) {
                        case 'Diaria':
                            $dueDate->addDay();
                            break;
                        case 'Semanal':
                            $dueDate->addWeek();
                            break;
                        case 'Quincenal':
                            $dueDate->addDays(15);
                            break;
                        case 'Mensual':
                            $dueDate->addMonth();
                            break;
                        default:
                            $dueDate->addMonth();
                    }
                    // Ajustar la nueva fecha si cae en día excluido
                    $dueDate = $adjustForExcludedDays($dueDate);
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al generar las cuotas');
        }
    }

    public function getClientCredits(string $search, int $perPage)
    {
        try {
            $user = Auth::user();
            $seller = $user->seller;

            $query = Credit::with(['client', 'seller'])
                ->select(
                    'client_id',
                    'seller_id',
                    DB::raw('count(*) as total_credits'),
                    DB::raw('sum(credit_value) as total_credit_value')
                )
                ->groupBy('client_id', 'seller_id');


            if ($user->role_id == 5 && $seller) {
                $query->whereHas('client', function ($q) use ($seller) {
                    $q->where('seller_id', $seller->id);
                });
            }


            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('client', function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('dni', 'like', "%{$search}%");
                    });
                });
            }

            $paginator = $query->paginate($perPage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Créditos de clientes obtenidos correctamente',
                'data' => $paginator
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener los créditos del cliente');
        }
    }

    public function getCredits(string $clientId, $page = 1, $perPage = 5, $search = null)
    {
        try {
            $query = Credit::query()
                ->where('client_id', $clientId)
                ->with(['client', 'seller', 'installments', 'payments'])
                ->orderBy('created_at', 'desc');

            $credits = $query->paginate($perPage, ['*'], 'page', $page);

            if ($credits->isEmpty()) {
                return $this->successResponse([
                    'data' => [],
                    'pagination' => [
                        'total' => 0,
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'last_page' => 1,
                    ]
                ]);
            }

            $paymentSummary = Payment::whereIn('credit_id', $credits->getCollection()->pluck('id'))
                ->select(
                    'credit_id',
                    'status',
                    DB::raw('SUM(amount) as total_amount')
                )
                ->groupBy('credit_id', 'status')
                ->get()
                ->groupBy('credit_id');

            $creditsWithSummary = $credits->getCollection()->map(function ($credit) use ($paymentSummary) {
                $summary = $paymentSummary->get($credit->id, collect());

                foreach ($summary as $item) {
                    $credit->{$item->status} = $item->total_amount;
                }

                return $credit;
            });

            $credits->setCollection($creditsWithSummary);

            return $this->successResponse([
                'data' => $credits->items(),
                'pagination' => [
                    'total' => $credits->total(),
                    'current_page' => $credits->currentPage(),
                    'per_page' => $credits->perPage(),
                    'last_page' => $credits->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener los créditos del cliente');
        }
    }

    public function getSellerCreditsByDate(int $sellerId, Request $request, int $perpage)
    {
        try {
            $creditsQuery = Credit::with(['client', 'client.images', 'installments', 'payments', 'images', 'renewedFrom', 'renewedFrom.payments'])
                // ->whereNull('renewed_from_id')
                ->where('seller_id', $sellerId);

            $timezone = $request->input('timezone', 'America/Lima');


            if ($request->has('start_date') && $request->has('end_date')) {
                $startDate = $request->get('start_date');
                $endDate = $request->get('end_date');

                $start = Carbon::parse($startDate, $timezone)->startOfDay()->timezone('UTC');
                $end = Carbon::parse($endDate, $timezone)->endOfDay()->timezone('UTC');
                $creditsQuery->whereBetween('credits.created_at', [$start, $end]);
            } elseif ($request->has('date')) {
                $filterDate = $request->get('date');
                $start = Carbon::parse($filterDate, $timezone)->startOfDay()->timezone('UTC');
                $end = Carbon::parse($filterDate, $timezone)->endOfDay()->timezone('UTC');
                $creditsQuery->whereBetween('credits.created_at', [$start, $end]);
            } else {
                $todayStart = Carbon::now($timezone)->startOfDay()->timezone('UTC');
                $todayEnd = Carbon::now($timezone)->endOfDay()->timezone('UTC');
                $creditsQuery->whereBetween('credits.created_at', [$todayStart, $todayEnd]);
            }

            $credits = $creditsQuery->get();

            $credits = $credits->map(function ($credit) {
                $startDate = $credit->start_date;
                $lastKey = $credit->installments->sortByDesc('due_date')->first();
                $endDate = $lastKey ? Carbon::parse($lastKey->due_date)->setTime(23, 59, 59)->format('Y-m-d H:i:s') : null;

                $credit->start_date = $startDate;
                $credit->end_date = $endDate;

                // FIX: Populate implicit previous credit if missing
                if (!$credit->renewed_from) {
                    $lastCredit = Credit::with(['payments', 'images', 'installments'])
                        ->where('client_id', $credit->client_id)
                        ->where('id', '<', $credit->id)
                        ->orderBy('created_at', 'desc')
                        ->first();

                    if ($lastCredit) {
                         // Attach as relation so it appears in JSON
                        $credit->setRelation('renewed_from', $lastCredit);
                    }
                }

                return $credit;
            });

            $sellerForTz = \App\Models\Seller::find($sellerId);
            $sellerTz = \App\Helpers\TimezoneHelper::getSellerTimezone($sellerForTz);

            // Convert to array to ensure renewed_from is included in JSON
            $creditsArray = $credits->map(function ($credit) use ($sellerTz) {
                $creditArray = $credit->toArray();
                // Ensure renewed_from is included even if it was set dynamically
                if ($credit->relationLoaded('renewed_from')) {
                    $creditArray['renewed_from'] = $credit->renewed_from ? $credit->renewed_from->toArray() : null;
                    \Log::info('Credit ID: ' . $credit->id . ' has renewed_from: ' . ($credit->renewed_from ? $credit->renewed_from->id : 'null'));
                } else {
                    \Log::info('Credit ID: ' . $credit->id . ' - renewed_from relation NOT loaded');
                }
                
                if (!isset($creditArray['business_timezone'])) {
                    $creditArray['business_timezone'] = $sellerTz;
                }

                return $creditArray;
            });

            return $this->successResponse([
                'success' => true,
                'message' => 'Créditos obtenidos correctamente para el vendedor y fecha(s) especificadas',
                'data' => $creditsArray
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los créditos del vendedor: ' . $e->getMessage(), 500);
        }
    }

    public function changeCreditClient($creditId, $newClientId)
    {
        try {
            $credit = Credit::find($creditId);
            if (!$credit) {
                return $this->errorResponse('El crédito no existe.', 404);
            }

            $newClient = Client::find($newClientId);
            if (!$newClient) {
                return $this->errorResponse('El nuevo cliente no existe.', 404);
            }

            $credit->client_id = $newClientId;
            $credit->save();

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente del crédito actualizado correctamente',
                'data' => $credit
            ]);
        } catch (\Exception $e) {
            \Log::error("Error al cambiar el cliente del crédito: " . $e->getMessage());
            return $this->errorResponse('Error al cambiar el cliente del crédito: ' . $e->getMessage(), 500);
        }
    }

    public function generateDailyReport($date)
    {
        $user = Auth::user();
        $sellerId = $user && $user->seller ? $user->seller->id : null;
        $maxDate = Carbon::now(self::TIMEZONE);
        $minDate = Carbon::now(self::TIMEZONE)->subDays(7);
        $reportDate = Carbon::createFromFormat('Y-m-d', $date, self::TIMEZONE);

        if ($reportDate->lt($minDate) || $reportDate->gt($maxDate)) {
            return $this->errorResponse('Solo se pueden consultar fechas dentro de los últimos 7 días', 422);
        }

        $liquidation = Liquidation::where('seller_id', $sellerId)
            ->whereDate('date', $reportDate->format('Y-m-d'))
            ->where('status', 'approved')
            ->first();

        if (!$liquidation) {
            return $this->errorResponse(
                'No puedes generar un reporte para este día. Contacta al vendedor para cerrar la liquidación correspondiente.',
                422
            );
        }


        // Obtener créditos con pagos en la fecha especificada usando business_date
        $creditsQuery = Credit::with(['client', 'installments', 'payments'])
            ->whereHas('payments', function ($query) use ($date) {
                $query->where('payments.business_date', $date);
            });


        if ($sellerId) {
            $creditsQuery->whereHas('client', function ($query) use ($sellerId) {
                $query->where('seller_id', $sellerId);
            });
        }

        $credits = $creditsQuery->get();

        // Obtener gastos del día
        $expensesQuery = Expense::whereBetween('expenses.created_at', [$start, $end]);
        if ($user) {
            $expensesQuery->where('user_id', $user->id);
        }
        $expenses = $expensesQuery->get();
        $totalExpenses = $expenses->sum('value');

        // Obtener ingresos del día
        $incomesQuery = Income::whereBetween('incomes.created_at', [$start, $end]);
        if ($user) {
            $incomesQuery->where('user_id', $user->id);
        }
        $incomes = $incomesQuery->get();
        $totalIncomes = $incomes->sum('value');

        $reportData = [];
        $totalCollected = 0;
        $totalAdditionalDisbursements = 0;
        $withPayment = 0;
        $withoutPayment = 0;
        $totalCapital = 0;
        $totalInterest = 0;
        $totalMicroInsurance = 0;
        $capitalCollected = 0;
        $interestCollected = 0;
        $microInsuranceCollected = 0;

        foreach ($credits as $index => $credit) {
            $interestAmount = $credit->credit_value * ($credit->total_interest / 100);
            $quotaAmount = ($credit->credit_value + $interestAmount) / $credit->number_installments;

            // Calcular el saldo actual (valor total - pagos realizados)
            $totalCreditValue = $credit->credit_value + $interestAmount;
            $credit->total_amount = $totalCreditValue; // Add to model in memory for consistency
            $totalPaid = $credit->payments->sum('amount');
            $remainingAmount = $totalCreditValue - $totalPaid;
            $dayPayments = $credit->payments()->whereBetween('payments.created_at', [$start, $end])->get();

            $positivePaid = $dayPayments->where('amount', '>=', 0)->sum('amount');
            $negativePaid = $dayPayments->where('amount', '<', 0)->sum('amount');
            $paidToday = $positivePaid;

            $paymentTime = $dayPayments->isNotEmpty() ? $dayPayments->last()->created_at->timezone(self::TIMEZONE)->format('H:i:s') : null;

            if ($positivePaid > 0) {
                $withPayment++;
            } else {
                $withoutPayment++;
            }

            $totalCollected += $positivePaid;
            $totalAdditionalDisbursements += abs($negativePaid);

            $totalCapital += $credit->credit_value;
            $totalInterest += $interestAmount;
            $totalMicroInsurance += $credit->micro_insurance_amount;

            // Calcular distribución del pago entre capital, interés y microseguro
            $totalCreditAmount = $credit->credit_value + $interestAmount;

            if ($totalCreditAmount > 0) {
                $capitalRatio = $credit->credit_value / $totalCreditAmount;
                $interestRatio = $interestAmount / $totalCreditAmount;
                $microInsuranceRatio = $credit->micro_insurance_amount / $totalCreditAmount;
            } else {
                $capitalRatio = $interestRatio = $microInsuranceRatio = 0;
            }

            $capitalCollected += $positivePaid * $capitalRatio;
            $interestCollected += $positivePaid * $interestRatio;
            $microInsuranceCollected += $positivePaid * $microInsuranceRatio;

            $reportData[] = [
                'no' => $index + 1,
                'client_name' => $credit->client->name,
                'credit_id' => $credit->id,
                'payment_frequency' => $credit->payment_frequency,
                'capital' => $credit->credit_value,
                'interest' => $interestAmount,
                'micro_insurance' => $credit->micro_insurance_amount,
                'total_credit' => $totalCreditValue,
                'quota_amount' => $quotaAmount,
                'remaining_amount' => $remainingAmount,
                'paid_today' => $paidToday,
                'payment_time' => $paymentTime,
                'client_needs_update' => (bool)$credit->client->needs_update,
            ];
        }

        // Obtener nuevos créditos del día
        $newCredits = Credit::whereBetween('credits.created_at', [$start, $end])
            ->whereNull('renewed_from_id');
        if ($sellerId) {
            $newCredits->whereHas('client', function ($query) use ($sellerId) {
                $query->where('seller_id', $sellerId);
            });
        }
        $newCredits = $newCredits->get();
        $totalNewCredits = $newCredits->sum('credit_value');

        // Calcular utilidad neta y neto entregado al cobrador
        // Net Utility = Collected (In) + Incomes (In) - Expenses (Out). (Disbursements are Principal movements, typically affects Cash but not "Utility" if Utility means Profit. Here utility seems to be Cash Flow)
        // If we strictly follow Cash Flow:
        $netUtility = $totalCollected + $totalIncomes - $totalExpenses;

        // Net Amount (Caja Final) = Collected - Expenses + Incomes - Additional Disbursements
        // Note: Existing code was '$totalCollected - $totalExpenses'. I am adding Incomes and subtracting Additional Disbursements.
        $netAmount = $totalCollected + $totalIncomes - $totalExpenses - $totalAdditionalDisbursements;
        $netUtilityPlusCapital = $netUtility + $totalCapital;

        return [
            'report_date' => $date,
            'report_data' => $reportData,
            'total_collected' => $totalCollected,
            'total_additional_disbursements' => $totalAdditionalDisbursements,
            'with_payment' => $withPayment,
            'without_payment' => $withoutPayment,
            'total_credits' => count($reportData),
            'new_credits' => $newCredits,
            'total_new_credits' => $totalNewCredits,
            'seller' => $sellerId ? Seller::find($sellerId) : null,
            'user' => $user,
            'expenses' => $expenses,
            'total_expenses' => $totalExpenses,
            'incomes' => $incomes,
            'total_incomes' => $totalIncomes,
            'total_capital' => $totalCapital,
            'total_interest' => $totalInterest,
            'total_micro_insurance' => $totalMicroInsurance,
            'capital_collected' => $capitalCollected,
            'interest_collected' => $interestCollected,
            'microinsurance_collected' => $microInsuranceCollected,
            'net_utility' => $netUtility,
            'net_amount' => $netAmount, // Ensure this key is used in frontend if needed. Usually frontend calculates own totals or uses this?
            // In the Step 194 screenshot, I saw "Caja actual del vendedor (Real a entregar) $1734.76". This likely comes from Liquidation object or this report.
            'net_utility_plus_capital' => $netUtilityPlusCapital,
        ];

    }
    public function generatePDF($reportData)
    {
        if ($reportData instanceof \Illuminate\Http\JsonResponse) {
            return $reportData;
        }

        $safeDate = \Carbon\Carbon::parse($reportData['report_date'])->format('Y-m-d');
        $filename = 'daily_collection_report_' . $safeDate . '.pdf';

        $pdf = Pdf::loadView('reports.daily-collection', $reportData);
        return $pdf->download($filename);
    }

    public function getReport($request)
    {
        $date = $request->date ?? \Carbon\Carbon::now(self::TIMEZONE)->format('Y-m-d');
        $reportData = $this->generateDailyReport($date);

        if ($reportData instanceof \Illuminate\Http\JsonResponse) {
            return $reportData;
        }

        if ($request->has('download') && $request->download == 'pdf') {
            return $this->generatePDF($reportData);
        }

        return $reportData;
    }

    public function generateCreditReport(int $creditId)
    {
        $credit = Credit::with([
            'client',
            'seller.city.country',
            'installments' => function ($q) {
                $q->orderBy('due_date', 'asc');
            },
            'payments'
        ])->find($creditId);

        if (!$credit) {
            return $this->errorResponse('El crédito no existe.', 404);
        }

        $today = Carbon::now(self::TIMEZONE)->startOfDay();

        $interestAmount = $credit->credit_value * ($credit->total_interest / 100);
        $totalCreditValue = $credit->credit_value + $interestAmount;
        $quotaAmount = $credit->number_installments > 0
            ? round($totalCreditValue / $credit->number_installments, 2)
            : 0;

        // Preparar datos de applied_amount por cuota y detalles
        try {
            $paymentInstallmentsDetails = \DB::table('payment_installments')
                ->join('payments', 'payment_installments.payment_id', '=', 'payments.id')
                ->where('payments.credit_id', $credit->id)
                ->select(
                    'payment_installments.installment_id',
                    'payment_installments.payment_id',
                    'payment_installments.applied_amount',
                    'payments.amount as payment_amount',
                    'payments.status as payment_status',
                    'payments.payment_date as payment_record_date',
                    'payments.created_at as payment_created_at'
                )
                ->orderBy('payment_installments.id', 'asc')
                ->get()
                ->groupBy('installment_id'); // grouped by installment_id
        } catch (\Throwable $e) {
            \Log::error("ERROR generateCreditReport - error querying payment_installments details: " . $e->getMessage(), [
                'credit_id' => $credit->id
            ]);
            $paymentInstallmentsDetails = collect();
        }

        try {
            $payments = \DB::table('payments')
                ->where('credit_id', $credit->id)
                ->select('id', 'amount', 'status', 'payment_date', 'created_at')
                ->orderBy('created_at', 'asc')
                ->get();
        } catch (\Throwable $e) {
            \Log::error("ERROR generateCreditReport - error querying payments: " . $e->getMessage(), [
                'credit_id' => $credit->id
            ]);
            $payments = collect();
        }

        $installmentsData = [];
        $acumPaid = 0;
        $overdueCounter = 0;

        // Pre-calc arrays for counts
        $totalInstallments = $credit->installments->count();
        $canceledCountTotal = $credit->installments->where('status', 'Cancelado')->count();

        foreach ($credit->installments as $index => $ins) {
            // Sumar lo aplicado a esta cuota
            $paidAmount = 0.0;
            $paymentsDetailsArr = [];

            if ($paymentInstallmentsDetails->has($ins->id)) {
                foreach ($paymentInstallmentsDetails->get($ins->id) as $row) {
                    $applied = (float) ($row->applied_amount ?? 0);
                    $paidAmount += $applied;

                    // payment_date prefer payment_record_date else created_at
                    $paymentDateRaw = $row->payment_record_date ?? $row->payment_created_at ?? null;
                    $paymentDate = $paymentDateRaw ? Carbon::parse($paymentDateRaw) : null;

                    // calcular days delay respecto a due_date
                    $delayDays = 0;
                    if ($paymentDate) {
                        $due = Carbon::parse($ins->due_date)->startOfDay();
                        if ($paymentDate->startOfDay()->greaterThan($due)) {
                            $delayDays = $paymentDate->startOfDay()->diffInDays($due);
                        } else {
                            $delayDays = 0;
                        }
                    }

                    $paymentsDetailsArr[] = [
                        'payment_id' => $row->payment_id,
                        'applied_amount' => round($applied, 2),
                        'payment_amount' => round((float) ($row->payment_amount ?? 0), 2),
                        'payment_status' => $row->payment_status ?? null,
                        'payment_date' => $paymentDate ? $paymentDate->format('Y-m-d') : null,
                        'days_delay' => $delayDays,
                    ];
                }
            }

            // Normalizar
            $paidAmount = round($paidAmount, 2);
            $acumPaid += $paidAmount;

            // pending amount for this installment
            $quotaAmountThis = $ins->quota_amount ?? $quotaAmount;
            $pendingForInstallment = max(0, round($quotaAmountThis - $paidAmount, 2));

            // Estado: si cuota completamente pagada -> 'Pagado', si cancelada -> 'Cancelado', else 'Pendiente'
            $status = $ins->status ?? 'Pendiente';
            if ($pendingForInstallment <= 0 && $status !== 'Cancelado') {
                $status = 'Pagado';
            }

            // Cuotas pagas hasta este punto (contar installments con paid fully)
            // Count installments with paid_amount >= quota_amount
            $paidInstallmentsCount = 0;
            // to compute efficiently: check previous installments in loop:
            for ($j = 0; $j <= $index; $j++) {
                $other = $credit->installments[$j];
                $otherPaid = 0.0;
                if ($paymentInstallmentsDetails->has($other->id)) {
                    foreach ($paymentInstallmentsDetails->get($other->id) as $r) {
                        $otherPaid += (float) ($r->applied_amount ?? 0);
                    }
                }
                $otherQuota = $other->quota_amount ?? $quotaAmount;
                if ($otherPaid >= $otherQuota)
                    $paidInstallmentsCount++;
            }

            // C.Pend: number of installments remaining with pending > 0
            $countPending = $credit->installments->filter(function ($it) use ($paymentInstallmentsDetails, $quotaAmount) {
                $paid = 0;
                if ($paymentInstallmentsDetails->has($it->id)) {
                    foreach ($paymentInstallmentsDetails->get($it->id) as $r) {
                        $paid += (float) ($r->applied_amount ?? 0);
                    }
                }
                $quota = $it->quota_amount ?? $quotaAmount;
                return $paid < $quota && $it->status !== 'Cancelado';
            })->count();

            // C.Canc: total canceled installments (or per installment status)
            $countCanceled = $credit->installments->filter(function ($it) {
                return $it->status === 'Cancelado' || $it->status === 'Anulado';
            })->count();

            // Atrasos: if there are payment details, show max days_delay among payments applied (or min); else if unpaid and due_date < today show days since due_date
            $daysDelayForInstallment = 0;
            if (!empty($paymentsDetailsArr)) {
                // take maximum delay among applied payments (a payment that covered it late)
                $daysDelayForInstallment = max(array_map(function ($x) {
                    return $x['days_delay'] ?? 0;
                }, $paymentsDetailsArr));
            } else {
                // if unpaid and due_date passed
                $dueDate = Carbon::parse($ins->due_date)->startOfDay();
                if ($dueDate->lt($today)) {
                    $daysDelayForInstallment = $today->diffInDays($dueDate);
                } else {
                    $daysDelayForInstallment = 0;
                }
            }

            // Balance remaining after this installment (total credit - acumPaid)
            $balanceRemaining = max(0, round($totalCreditValue - $acumPaid, 2));


            $installmentsData[] = [
                'no' => $index + 1,
                'due_date' => Carbon::parse($ins->due_date)->format('Y-m-d'),
                'quota_amount' => round($quotaAmountThis, 2),
                'cuo_pagas' => $paidInstallmentsCount,
                'status' => $status,
                'paid_amount' => round($paidAmount, 2),
                'acum_paid' => round($acumPaid, 2),
                'pending_amount' => round($pendingForInstallment, 2),
                'balance' => round($balanceRemaining, 2),
                'count_pending' => $countPending,
                'count_canceled' => $countCanceled,
                'days_delay' => $daysDelayForInstallment,
                'payments_details' => $paymentsDetailsArr,
            ];
        }

        // Construir payments_list usado en la vista (cada pago y a qué cuotas se aplicó)
        $paymentsList = [];
        foreach ($payments as $p) {
            $appliedRows = \DB::table('payment_installments')
                ->join('installments', 'payment_installments.installment_id', '=', 'installments.id')
                ->where('payment_installments.payment_id', $p->id)
                ->select('payment_installments.installment_id', 'payment_installments.applied_amount', 'installments.quota_number', 'installments.due_date')
                ->get();

            $appliedTo = [];
            foreach ($appliedRows as $ar) {
                $paymentDateRaw = $p->payment_date ?? $p->created_at;
                $paymentDate = $paymentDateRaw ? Carbon::parse($paymentDateRaw) : null;
                $due = Carbon::parse($ar->due_date)->startOfDay();
                $delayDays = 0;
                if ($paymentDate && $paymentDate->startOfDay()->greaterThan($due)) {
                    $delayDays = $paymentDate->startOfDay()->diffInDays($due);
                }
                $appliedTo[] = [
                    'installment_id' => $ar->installment_id,
                    'quota_number' => $ar->quota_number,
                    'due_date' => Carbon::parse($ar->due_date)->format('Y-m-d'),
                    'applied_amount' => round((float) ($ar->applied_amount ?? 0), 2),
                    'days_delay' => $delayDays,
                ];
            }

            $paymentsList[] = [
                'payment_id' => $p->id,
                'amount' => round((float) $p->amount, 2),
                'status' => $p->status,
                'created_at' => Carbon::parse($p->created_at)->format('Y-m-d H:i:s'),
                'payment_date' => $p->payment_date ? Carbon::parse($p->payment_date)->format('Y-m-d H:i:s') : Carbon::parse($p->created_at)->format('Y-m-d H:i:s'),
                'is_global' => (count($appliedTo) === 0),
                'applied_to' => $appliedTo,
            ];
        }

        // Totales
        $totalApplied = collect($installmentsData)->sum('paid_amount');
        $totalCollected = $totalApplied + (float) (\DB::table('payments')->leftJoin('payment_installments', 'payments.id', '=', 'payment_installments.payment_id')->where('payments.credit_id', $credit->id)->whereNull('payment_installments.id')->sum('payments.amount'));

        $report = [
            'credit' => $credit,
            'client' => $credit->client,
            'seller' => $credit->seller,
            'report_date' => Carbon::now(self::TIMEZONE)->format('Y-m-d'),
            'start_date' => $credit->first_quota_date,
            'end_date' => optional($credit->installments->sortByDesc('due_date')->first())->due_date,
            'total_credit_value' => round($totalCreditValue, 2),
            'capital' => round($credit->credit_value, 2),
            'interest' => round($interestAmount, 2),
            'micro_insurance' => round($credit->micro_insurance_amount ?? 0, 2),
            'quota_amount' => $quotaAmount,
            'number_installments' => $credit->number_installments,
            'installments' => $installmentsData,
            'payments_list' => $paymentsList,
            'total_collected' => round($totalCollected, 2),
            'total_applied' => round($totalApplied, 2),
        ];

        return $report;
    }


    /**
     * Genera y descarga el PDF a partir de los datos generados por generateCreditReport.
     *
     * @param array $reportData
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    public function generateCreditPDF($reportData)
    {
        if ($reportData instanceof \Illuminate\Http\JsonResponse) {
            return $reportData;
        }

        $safeDate = Carbon::parse($reportData['report_date'])->format('Y-m-d');
        $filename = 'credit_detail_' . ($reportData['credit']->id ?? 'unknown') . '_' . $safeDate . '.pdf';

        $pdf = Pdf::loadView('reports.credit-details', $reportData);
        return $pdf->download($filename);
    }

    /**
     * Punto de entrada: devuelve datos o descarga directa si ?download=pdf
     *
     * @param \Illuminate\Http\Request $request
     * @param int $creditId
     * @return array|\Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    public function getCreditReport($request, int $creditId)
    {
        $reportData = $this->generateCreditReport($creditId);

        if ($reportData instanceof \Illuminate\Http\JsonResponse) {
            return $reportData;
        }

        if ($request->has('download') && $request->download === 'pdf') {
            return $this->generateCreditPDF($reportData);
        }

        return $reportData;
    }

    /**
     * Simulates a change in the credit schedule (initial date or frequency)
     *
     * @param int $creditId
     * @param string $newDate Base date for calculation
     * @param string $type Type of change ('schedule' or 'frequency')
     * @param string|null $newFrequency New frequency if applicable
     * @return \Illuminate\Http\JsonResponse
     */
    public function simulateScheduleChange(
        int $creditId,
        ?string $newDate = null,
        string $type = 'schedule',
        ?string $newFrequency = null,
        ?int $newInstallments = null,
        ?float $newInterestRate = null,
        ?float $newInsurancePercentage = null,
        ?string $newStartDate = null,
        ?float $newCreditValue = null,
        bool $recalculatePaid = true
    ) {
        try {
            $credit = Credit::with(['installments'])->findOrFail($creditId);
            $tz = self::TIMEZONE;
            $adjustForExcludedDays = $this->getExcludedDaysAdjuster($credit);

            $frequency = $newFrequency ?? $credit->payment_frequency;
            $totalInstallments = $newInstallments ?? $credit->number_installments;
            $interestRate = $newInterestRate ?? $credit->total_interest;
            $insurancePercentage = $newInsurancePercentage ?? $credit->micro_insurance_percentage ?? 0;
            $creditValue = $newCreditValue ?? (float) $credit->credit_value;

            // Recalcular montos si hay cambios financieros
            // El costo total se basa en el interés. El seguro generalmente ya está contemplado o es informativo en el total a pagar.
            $newTotalCost = ($creditValue * (1 + ($interestRate / 100)));

            $currentInstallments = $credit->installments->sortBy('quota_number');
            $paidAmountSum = \App\Models\Payment::where('credit_id', $creditId)->where('status', '!=', 'Anulado')->sum('amount');
            
            // Si recalculamos todo desde la cuota 1 (FIFO real)
            if ($recalculatePaid) {
                $newQuotaAmount = $totalInstallments > 0 ? round($newTotalCost / $totalInstallments, 2) : 0;
            } else {
                $paidInstallments = $currentInstallments->filter(function ($i) {
                    return in_array(strtolower($i->status), ['pagado', 'paid', 'pagada']);
                });
                $paidAmountSumLegacy = $paidInstallments->sum('quota_amount');
                $paidCount = $paidInstallments->count();

                if (!$recalculatePaid && $totalInstallments < $paidCount) {
                    throw new \Exception("El nuevo número de cuotas ($totalInstallments) no puede ser menor a las ya pagadas ($paidCount).");
                }

                $pendingCount = $totalInstallments - $paidCount;
                $remainingCost = $newTotalCost - $paidAmountSumLegacy;
                $newQuotaAmount = $pendingCount > 0 ? round($remainingCost / $pendingCount, 2) : 0;
            }

            // Generar nuevas fechas
            $startDateStr = $newDate;
            if (!$startDateStr) {
                $firstPending = $currentInstallments->whereNotIn('status', ['Pagado', 'Paid', 'Pagada'])->first();
                $startDateStr = $firstPending ? $firstPending->due_date : $credit->first_quota_date;
            }
            $startDate = Carbon::parse($startDateStr, $tz);

            // Si recalculamos todo, empezamos fechas de cuota 1. Si no, después de las pagadas.
            $startAtQuota = $recalculatePaid ? 0 : ($paidCount ?? 0);
            $newDates = $this->getNewScheduleDates($totalInstallments, $startDate, $frequency, $adjustForExcludedDays, $startAtQuota);

            $simulatedInstallments = [];
            $changesCount = 0;

            // En lugar de una suma global, obtenemos los pagos individuales ordenados por fecha
            $paymentsStack = \App\Models\Payment::where('credit_id', $creditId)
                ->where('status', '!=', 'Anulado')
                ->orderBy('payment_date', 'asc')
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function($p) {
                    return [
                        'amount' => (float)$p->amount, 
                        'unapplied' => (float)$p->amount,
                        'payment_date' => $p->payment_date instanceof \Carbon\Carbon ? $p->payment_date->format('Y-m-d') : $p->payment_date
                    ];
                })
                ->toArray();

            for ($i = 1; $i <= $totalInstallments; $i++) {
                $existing = $currentInstallments->where('quota_number', $i)->first();
                $simDate = $newDates[$i] ?? ($existing ? $existing->due_date : null);
                
                $status = 'Pendiente';
                $isPaid = false;
                $simAmount = $newQuotaAmount;

                if ($recalculatePaid) {
                    $needed = $simAmount;
                    $coveredAmount = 0;
                    $lastPaymentDate = null;

                    foreach ($paymentsStack as &$p) {
                        if ($needed <= 0.001) break;
                        if ($p['unapplied'] <= 0.001) continue;

                        $take = min($p['unapplied'], $needed);
                        $p['unapplied'] -= $take;
                        $needed -= $take;
                        $coveredAmount += $take;
                        $lastPaymentDate = $p['payment_date'];
                    }

                    if ($coveredAmount >= $simAmount - 0.001) {
                        $status = 'Pagado';
                        $isPaid = true;
                        $paymentDate = $lastPaymentDate;
                    } elseif ($coveredAmount > 0.001) {
                        $status = 'Abonado';
                        $paymentDate = $lastPaymentDate;
                    }
                } else {
                    if ($i <= ($paidCount ?? 0)) {
                        $status = 'Pagado';
                        $isPaid = true;
                        $simAmount = (float)($existing->quota_amount ?? 0);
                        $simDate = $existing->due_date;
                        // Para cuotas ya pagadas originalmente, buscar la fecha del último abono aplicado
                        $paymentDate = $existing ? \App\Models\PaymentInstallment::where('installment_id', $existing->id)->latest()->first()?->payment?->payment_date : null;
                    }
                }

                $item = [
                    'id' => $existing ? $existing->id : null,
                    'quota_number' => $i,
                    'current_date' => $existing ? $existing->due_date : null,
                    'simulated_date' => $simDate,
                    'payment_date' => $paymentDate ?? null,
                    'current_amount' => $existing ? (float) $existing->quota_amount : null,
                    'simulated_amount' => (float)$simAmount,
                    'status' => $status,
                    'is_paid' => $isPaid,
                    'changed' => true
                ];

                if ($existing) {
                    $item['changed'] = ($existing->due_date !== $simDate) || (abs((float) $existing->quota_amount - $simAmount) > 0.01);
                }

                if ($item['changed'])
                    $changesCount++;
                
                $simulatedInstallments[] = $item;
            }

            $simPaidCount = collect($simulatedInstallments)->where('is_paid', true)->count();
            $simPendingCount = $totalInstallments - $simPaidCount;
            
            // Saldos actuales para información del simulador
            $unappliedTotal = \App\Models\Payment::where('credit_id', $creditId)->where('status', '!=', 'Anulado')->sum('unapplied_amount');

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'credit_id' => $credit->id,
                    'type' => $type,
                    'current_frequency' => $credit->payment_frequency,
                    'simulated_frequency' => $frequency,
                    'current_total_cost' => round(((float) $credit->credit_value * (1 + ($credit->total_interest / 100))), 2),
                    'new_total_cost' => round($newTotalCost, 2),
                    'installments' => $simulatedInstallments,
                    'summary' => [
                        'total_installments' => $totalInstallments,
                        'paid_installments' => $simPaidCount,
                        'pending_installments' => $simPendingCount,
                        'modified_installments' => $changesCount,
                        'new_quota_amount' => $newQuotaAmount,
                        'new_final_date' => !empty($simulatedInstallments) ? end($simulatedInstallments)['simulated_date'] : null,
                        'total_paid' => (float)$paidAmountSum,
                        'unapplied_total' => (float)array_sum(array_column($paymentsStack, 'unapplied'))
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error simulateScheduleChange ({$creditId}): " . $e->getMessage());
            return $this->errorResponse('Error al simular cambios: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Helper to calculate installment dates sequence
     */
    private function getNewScheduleDates(int $numInstallments, Carbon $startDate, string $frequency, $adjustForExcludedDays, int $startAtQuota = 0)
    {
        $dates = [];
        $currentDate = $startDate->copy();
        $currentDate = $adjustForExcludedDays($currentDate);

        for ($i = $startAtQuota + 1; $i <= $numInstallments; $i++) {
            $dates[$i] = $currentDate->format('Y-m-d');

            switch ($frequency) {
                case 'Diaria':
                    $currentDate->addDay();
                    break;
                case 'Semanal':
                    $currentDate->addWeek();
                    break;
                case 'Quincenal':
                    $currentDate->addDays(15);
                    break;
                case 'Mensual':
                    $currentDate->addMonth();
                    break;
                default:
                    $currentDate->addMonth();
            }
            $currentDate = $adjustForExcludedDays($currentDate);
        }

        return $dates;
    }

    /**
     * Helper to get a closure that adjusts dates based on excluded days
     */
    private function getExcludedDaysAdjuster(Credit $credit)
    {
        $excludedDayNames = json_decode($credit->excluded_days ?? '[]', true) ?? [];
        $dayMap = [
            'Domingo' => Carbon::SUNDAY,
            'Lunes' => Carbon::MONDAY,
            'Martes' => Carbon::TUESDAY,
            'Miércoles' => Carbon::WEDNESDAY,
            'Jueves' => Carbon::THURSDAY,
            'Viernes' => Carbon::FRIDAY,
            'Sábado' => Carbon::SATURDAY
        ];

        $excludedDayNumbers = [];
        foreach ($excludedDayNames as $dayName) {
            if (isset($dayMap[$dayName])) {
                $excludedDayNumbers[] = $dayMap[$dayName];
            }
        }

        return function (Carbon $date) use ($excludedDayNumbers) {
            while (in_array($date->dayOfWeek, $excludedDayNumbers)) {
                $date->addDay();
            }
            return $date;
        };
    }
}
