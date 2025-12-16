<?php

namespace App\Services;

use App\Helpers\Helper;
use App\Traits\ApiResponse;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
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
use Barryvdh\DomPDF\Facade\Pdf;

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

            // Calcular fecha de primera cuota si no se proporciona
            if ($params['is_advance_payment']) {
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
                $today = Carbon::now($userTimezone)->toDateString();
                $newCreditsAmount = \App\Models\Credit::where('seller_id', $params['seller_id'])
                    ->whereDate('created_at', $today)
                    ->sum('credit_value');
                $totalWithNew = $newCreditsAmount + floatval(   $params['credit_value']);
                if ($totalWithNew > $limit) {
                    return $this->errorResponse('No puedes crear el crédito. El monto total de ventas nuevas por el cobrador hoy supera el límite de $' . number_format($limit, 2), 403);
                }
            }

            $creditData = [
                'client_id' => $params['client_id'],
                'seller_id' => $params['seller_id'],
                'guarantor_id' => $params['guarantor_id'] ?? null,
                'credit_value' => $params['credit_value'],
                'total_interest' => $params['interest_rate'],
                'number_installments' => $params['installment_count'],
                'payment_frequency' => $params['payment_frequency'],
                'excluded_days' => json_encode($params['excluded_days'] ?? []),
                'micro_insurance_percentage' => $params['micro_insurance_percentage'] ?? null,
                'micro_insurance_amount' => $params['micro_insurance_amount'] ?? null,
                'first_quota_date' => $firstQuotaDate,
                'is_advance_payment' => $params['is_advance_payment'],
                'status' => 'Vigente',
                'created_at' => $params['created_at'] ?? null,
                'updated_at' => $params['updated_at'] ?? null
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

            $quotaAmount = (($credit->credit_value * $credit->total_interest / 100) + $credit->credit_value) / $credit->number_installments;

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
                    'created_at' => $params['created_at'] ?? null,
                    'updated_at' => $params['updated_at'] ?? null
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
                foreach ($images as $index => $imageData) {
                    $imageFile = $request->file("images.{$index}.file");

                    $imagePath = Helper::uploadFile($imageFile, 'clients');

                    $credit->client->images()->create([
                        'path' => $imagePath,
                        'type' => $imageData['type'],
                        'created_at' => $params['created_at'] ?? null,
                        'updated_at' => $params['updated_at'] ?? null
                    ]);
                }
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Crédito creado con éxito',
                'data' => [
                    'credit' => $credit,
                    'first_quota_date' => $credit->first_quota_date,
                    'adjusted_first_date' => $dueDate->format('Y-m-d'),
                    'total_installments' => $credit->number_installments
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error("Error al crear crédito: " . $e->getMessage());
            /* \Log::error($e->getTraceAsString()); */
            return $this->errorResponse('Error al crear el crédito: ' . $e->getMessage(), 500);
        }
    }

    public function renew(Request $request)
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

            $newCredit = Credit::create([
                'client_id'        => $oldCredit->client_id,
                'seller_id'        => $oldCredit->seller_id,
                'credit_value'     => $request->new_credit_value,
                'total_interest'   => $request->input('interest_rate', $oldCredit->total_interest),
                'number_installments' => $request->input('installment_count', $oldCredit->number_installments),
                'payment_frequency'   => $request->input('payment_frequency', $oldCredit->payment_frequency),
                'first_quota_date'    => $firstQuotaDate,
                'previous_pending_amount' => $pendingAmount,
                'renewed_from_id'     => $request->old_credit_id,
                'status'           => 'Vigente',
                'created_at' => $createdAt,
                'updated_at' => $updatedAt
            ]);

            $quotaAmount = (($newCredit->credit_value * $newCredit->total_interest / 100) + $newCredit->credit_value) / $newCredit->number_installments;

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
                    'credit_id'    => $newCredit->id,
                    'quota_number' => $i,
                    'due_date'     => $dueDate->format('Y-m-d'),
                    'quota_amount' => round($quotaAmount, 2),
                    'status'       => 'Pendiente',
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
            $netDisbursement = $request->new_credit_value - $pendingAmount;
            /*        Payment::create([
                'credit_id' => $newCredit->id,
                'amount'    => $netDisbursement,
                'type'      => 'Desembolso renovación',
                'payment_date' => now(),
                'status'    => 'Pagado',
            ]); */

            \DB::commit();

            // 5. Retornar desglose
            return $this->successResponse([
                'success'           => true,
                'monto_total_nuevo' => $request->new_credit_value,
                'saldo_pagado'      => $pendingAmount,
                'desembolso_neto'   => $netDisbursement,
                'credit'            => $newCredit,
                'old_credit'        => $oldCredit,
            ]);
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error("Error en renovación de crédito: " . $e->getMessage());
            return $this->errorResponse('Error en renovación de crédito: ' . $e->getMessage(), 500);
        }
    }

        public function updateCreditSchedule(int $creditId, string $newFirstQuotaDate, $timezone = null)
    {
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

            // Inicial fecha de la primera cuota en la zona del usuario
            $dueDate = Carbon::parse($newFirstQuotaDate, $tz);
            $dueDate = $adjustForExcludedDays($dueDate);

            // Ordenar cuotas por número de cuota y actualizar due_date secuencialmente
            $installments = $credit->installments->sortBy('quota_number');

            foreach ($installments as $inst) {
                $inst->due_date = $dueDate->format('Y-m-d');
                $inst->save();

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

            // Actualizar primera cuota en el crédito (mantener zona UTC/como string)
            $credit->first_quota_date = $newFirstQuotaDate;
            $credit->save();

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Fechas de cuotas actualizadas correctamente',
                'data' => [
                    'credit_id' => $credit->id,
                    'first_quota_date' => $credit->first_quota_date,
                    'installments_updated' => $installments->map(function($i){ return ['id'=>$i->id,'quota_number'=>$i->quota_number,'due_date'=>$i->due_date]; })
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Error updateCreditSchedule ({$creditId}): " . $e->getMessage());
            return $this->errorResponse('Error al actualizar el calendario de cuotas: ' . $e->getMessage(), 500);
        }
    }

        public function updateCreditFrequency(int $creditId, string $newFrequency, ?string $newFirstQuotaDate = null, $timezone = null)
    {
        try {
            DB::beginTransaction();

            $tz = $timezone ?: self::TIMEZONE;
            $credit = Credit::with(['installments'])->findOrFail($creditId);

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

            $adjustForExcludedDays = function (Carbon $date) use ($excludedDayNumbers) {
                while (in_array($date->dayOfWeek, $excludedDayNumbers)) {
                    $date->addDay();
                }
                return $date;
            };

            // Fecha inicial: preferir la nueva si se envía, si no usar la existente del crédito
            $baseDateStr = $newFirstQuotaDate ?? $credit->first_quota_date;
            $dueDate = Carbon::parse($baseDateStr, $tz);
            $dueDate = $adjustForExcludedDays($dueDate);

            // Recalcular due_date para cada cuota según la nueva frecuencia
            $installments = $credit->installments->sortBy('quota_number');

            foreach ($installments as $inst) {
                $inst->due_date = $dueDate->format('Y-m-d');
                $inst->save();

                // Avanzar la fecha según la nueva frecuencia
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
                        // si frecuencia no reconocida, usar mensual por defecto
                        $dueDate->addMonth();
                }

                // Ajustar si cae en día excluido
                $dueDate = $adjustForExcludedDays($dueDate);
            }

            // Guardar cambios en el crédito
            $credit->payment_frequency = $newFrequency;
            if ($newFirstQuotaDate) {
                $credit->first_quota_date = $newFirstQuotaDate;
            }
            $credit->save();

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Frecuencia y calendario de cuotas actualizados correctamente',
                'data' => [
                    'credit_id' => $credit->id,
                    'payment_frequency' => $credit->payment_frequency,
                    'first_quota_date' => $credit->first_quota_date,
                    'installments_updated' => $installments->map(function ($i) {
                        return ['id' => $i->id, 'quota_number' => $i->quota_number, 'due_date' => $i->due_date];
                    })
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Error updateCreditFrequency ({$creditId}): " . $e->getMessage());
            return $this->errorResponse('Error al actualizar la frecuencia del crédito: ' . $e->getMessage(), 500);
        }
    }

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

    public function delete($creditId, $timezone = null)
    {
        try {
            DB::beginTransaction();

            $credit = Credit::with(['seller', 'installments', 'payments'])->find($creditId);


            if (!$credit) {
                DB::rollBack();
                return $this->errorResponse('El crédito no existe.', 404);
            }

            if ($credit->payments()->exists()) {
                DB::rollBack();
                return $this->errorResponse(
                    'No se puede eliminar el crédito porque tiene pagos registrados.',
                    403
                );
            }


            $liquidationExists = Liquidation::where('seller_id', $credit->seller_id)
                ->whereDate('created_at', Carbon::today())
                ->where('status', operator: 'approved')
                ->exists();

            if ($liquidationExists) {
                DB::rollBack();
                return $this->errorResponse(
                    'No se puede eliminar el crédito. El vendedor ya tiene una liquidación registrada para el día de hoy.',
                    403
                );
            }

            if ($timezone) {
                $now = Carbon::now($timezone);
                foreach ($credit->installments as $installment) {
                    $installment->deleted_at = $now;
                    $installment->save();
                    $installment->delete();
                }
                $credit->deleted_at = $now;
                $credit->save();
                $credit->delete();
            } else {
                $credit->installments()->delete();
                $credit->delete();
            }

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Crédito eliminado correctamente',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error al eliminar el crédito con ID {$creditId}: " . $e->getMessage());
            return $this->errorResponse('Error al eliminar el crédito: ' . $e->getMessage(), 500);
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

    public function show($creditId)
    {
        try {
            $credit = Credit::with(['client', 'guarantor', 'route'])->find($creditId);

            if (!$credit) {
                return $this->errorResponse('El crédito no existe.', 404);
            }

            return $this->successResponse([
                'success' => true,
                'data' => $credit
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
                'credit_value' => $params['credit_value'],
                'total_interest' => $params['interest_rate'],
                'number_installments' => $params['installment_count'],
                'payment_frequency' => $params['payment_frequency'],
                'excluded_days' => json_encode($params['excluded_days'] ?? []),
                'micro_insurance_percentage' => $params['micro_insurance_percentage'] ?? null,
                'micro_insurance_amount' => $params['micro_insurance_amount'] ?? null,
                'first_quota_date' => $firstQuotaDate,
                'status' => 'Vigente',
                'unification_reason' => $params['description'] ?? null,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt
            ]);

            // 3. Generar cuotas para el nuevo crédito
            $quotaAmount = (($newCredit->credit_value * $newCredit->total_interest / 100) + $newCredit->credit_value) / $newCredit->number_installments;
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

            $paymentSummary = Payment::whereIn('credit_id', $credits->pluck('id'))
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
            $creditsQuery = Credit::with(['client.images', 'installments', 'payments'])
                ->whereNull('renewed_from_id')
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

            $clientIds = $credits->pluck('client_id')->unique()->values();

            $allClientCredits = Credit::with(['installments', 'payments'])
                ->whereIn('client_id', $clientIds)
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->get()
                ->groupBy('client_id');

            $getImagesBetween = function ($images, ?Carbon $start, ?Carbon $end) {
                if (!$images) {
                    return collect();
                }

                return $images
                    ->filter(function ($img) use ($start, $end) {
                        if (!$img->created_at) {
                            return false;
                        }

                        $createdAt = $img->created_at instanceof Carbon
                            ? $img->created_at
                            : Carbon::parse($img->created_at);

                        if ($start && $createdAt->lt($start)) {
                            return false;
                        }

                        if ($end && $createdAt->gte($end)) {
                            return false;
                        }

                        return true;
                    })
                    ->values();
            };

            $credits = $credits->map(function ($credit) use ($allClientCredits, $getImagesBetween) {
                $startDate = $credit->start_date;
                $lastInstallment = $credit->installments->sortByDesc('due_date')->first();
                $endDate = $lastInstallment
                    ? Carbon::parse($lastInstallment->due_date)->setTime(23, 59, 59)->format('Y-m-d H:i:s')
                    : null;

                $credit->start_date = $startDate;
                $credit->end_date = $endDate;

                $clientCredits = $allClientCredits->get($credit->client_id, collect());
                $currentIndex = $clientCredits->search(function ($c) use ($credit) {
                    return $c->id === $credit->id;
                });

                $previousCredit = null;
                if ($currentIndex !== false) {
                    $previousCredit = $clientCredits->get($currentIndex + 1);
                } else {
                    $previousCredit = $clientCredits
                        ->first(function ($c) use ($credit) {
                            return $c->created_at < $credit->created_at;
                        });
                }

                $credit->previous_credit = $previousCredit;

                $clientImages = $credit->client && $credit->client->images
                    ? $credit->client->images->sortBy('created_at')->values()
                    : collect();

                $nextNewerCredit = null;
                if ($currentIndex !== false) {
                    $nextNewerCredit = $currentIndex > 0 ? $clientCredits->get($currentIndex - 1) : null;
                }

                $currentStart = $credit->created_at ? Carbon::parse($credit->created_at) : null;
                $currentEnd = $nextNewerCredit && $nextNewerCredit->created_at
                    ? Carbon::parse($nextNewerCredit->created_at)
                    : null;

                $credit->images = $getImagesBetween($clientImages, $currentStart, $currentEnd);

                if ($previousCredit && $previousCredit->created_at) {
                    $prevStart = Carbon::parse($previousCredit->created_at);
                    $prevEnd = $currentStart;
                    $credit->previous_credit_images = $getImagesBetween($clientImages, $prevStart, $prevEnd);
                } else {
                    $credit->previous_credit_images = collect();
                }

                return $credit;
            });

            return $this->successResponse([
                'success' => true,
                'message' => 'Créditos obtenidos correctamente para el vendedor y fecha(s) especificadas',
                'data' => $credits
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


        $start = $reportDate->copy()->startOfDay()->timezone('UTC');
        $end = $reportDate->copy()->endOfDay()->timezone('UTC');

        // Obtener créditos con pagos en la fecha especificada
        $creditsQuery = Credit::with(['client', 'installments', 'payments'])
            ->whereHas('payments', function ($query) use ($start, $end) {
                $query->whereBetween('payments.created_at', [$start, $end]);
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
            $quotaAmount = ($credit->credit_value + $interestAmount + $credit->micro_insurance_amount) / $credit->number_installments;

            // Calcular el saldo actual (valor total - pagos realizados)
            $totalCreditValue = $credit->credit_value + $interestAmount + $credit->micro_insurance_amount;
            $totalPaid = $credit->payments->sum('amount');
            $remainingAmount = $totalCreditValue - $totalPaid;
            $dayPayments = $credit->payments()->whereBetween('payments.created_at', [$start, $end])->get();
            $paidToday = $dayPayments->sum('amount');
            $paymentTime = $dayPayments->isNotEmpty() ? $dayPayments->last()->created_at->timezone(self::TIMEZONE)->format('H:i:s') : null;

            if ($paidToday > 0) {
                $withPayment++;
            } else {
                $withoutPayment++;
            }

            $totalCollected += $paidToday;
            $totalCapital += $credit->credit_value;
            $totalInterest += $interestAmount;
            $totalMicroInsurance += $credit->micro_insurance_amount;

            // Calcular distribución del pago entre capital, interés y microseguro
            $totalCreditAmount = $credit->credit_value + $interestAmount + $credit->micro_insurance_amount;

            if ($totalCreditAmount > 0) {
                $capitalRatio = $credit->credit_value / $totalCreditAmount;
                $interestRatio = $interestAmount / $totalCreditAmount;
                $microInsuranceRatio = $credit->micro_insurance_amount / $totalCreditAmount;
            } else {
                $capitalRatio = $interestRatio = $microInsuranceRatio = 0;
            }

            $capitalCollected += $paidToday * $capitalRatio;
            $interestCollected += $paidToday * $interestRatio;
            $microInsuranceCollected += $paidToday * $microInsuranceRatio;

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
        $netUtility = $totalCollected + $totalIncomes - $totalExpenses;
        $netAmount = $totalCollected - $totalExpenses;
        $netUtilityPlusCapital = $netUtility + $totalCapital;

        return [
            'report_date' => $date,
            'report_data' => $reportData,
            'total_collected' => $totalCollected,
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
        $microInsurance = ($credit->credit_value * $credit->micro_insurance_percentage) / 100 ?? 0;
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
                if ($otherPaid >= $otherQuota) $paidInstallmentsCount++;
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
            'micro_insurance' => round($microInsurance, 2),
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
}
