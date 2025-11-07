<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\Income;
use App\Models\LiquidationAudit;
use App\Services\LiquidationService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Liquidation;
use App\Models\Expense;
use App\Models\Credit;
use App\Models\Payment;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use App\Notifications\GeneralNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\Liquidation\CalculateLiquidationRequest;
use App\Http\Requests\Liquidation\StoreLiquidationRequest;
use App\Http\Requests\Liquidation\UpdateLiquidationRequest;
use App\Http\Requests\Liquidation\ReopenRouteRequest;
use App\Http\Requests\Liquidation\LiquidationHistoryRequest;
use App\Exports\LiquidationExport;

class LiquidationController extends Controller
{
    use ApiResponse;
    protected $liquidationService;
    public function __construct(LiquidationService $liquidationService)
    {
        $this->liquidationService = $liquidationService;
    }
    public function calculateLiquidation(CalculateLiquidationRequest $request)
    {
        $user = Auth::user();
        $sellerId = $request->seller_id;
        $date = $request->date;

        $timezone = 'America/Lima';
        $dateLocal = Carbon::parse($date, $timezone)->format('Y-m-d');

        // Verificar permisos
        if (!$this->checkAuthorization($user, $sellerId)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Obtener datos para la liquidación
        $data = $this->getLiquidationData($sellerId, $dateLocal, $user->id);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function storeLiquidation(StoreLiquidationRequest $request)
    {
        $user = Auth::user();


        $timezone = $request->input('timezone', 'America/Lima');
        $todayDate = Carbon::now($timezone)->toDateString();

        // Verificar si ya existe liquidación para este día
        $existingLiquidation = Liquidation::where('seller_id', $request->seller_id)
            ->whereDate('date', $request->date)
            ->where('status', 'approved')
            ->first();

        if ($existingLiquidation && $user->role_id !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe una liquidación para este vendedor en la fecha seleccionada'
            ], 422);
        }

        $pendingExpenses = Expense::where('user_id', $user->id)
            ->whereDate('created_at', $request->date)
            ->where('status', 'Pendiente')
            ->exists();

        if ($pendingExpenses) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede liquidar porque tienes gastos pendientes de aprobación en la fecha seleccionada'
            ], 422);
        }

        // === Calcular créditos irrecuperables ===
        $irrecoverableCredits = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $request->seller_id)
            ->where('credits.status', 'Cartera Irrecuperable')
            ->whereDate('credits.updated_at', $request->date)
            ->where('installments.status', 'Pendiente')
            ->sum('installments.quota_amount');

        // === Calcular renovaciones desembolsadas ===
        $startUTC = Carbon::parse($request->date, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($request->date, $timezone)->endOfDay()->setTimezone('UTC');

        $renewalCredits = Credit::where('seller_id', $request->seller_id)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNotNull('renewed_from_id')
            ->get();

        $total_renewal_disbursed = 0;
        foreach ($renewalCredits as $renewCredit) {
            $oldCredit = Credit::find($renewCredit->renewed_from_id);
            $pendingAmount = 0;
            $oldCreditTotal = 0;
            $oldCreditPaid = 0;
            if ($oldCredit) {
                $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                $oldCreditPaid = Payment::where('credit_id', $oldCredit->id)->sum('amount');
                $pendingAmount = $oldCreditTotal - $oldCreditPaid;
            }
            $netDisbursement = $renewCredit->credit_value - $pendingAmount;
            $total_renewal_disbursed += $netDisbursement;
        }

        // === Calcular valor de póliza ===
        $poliza = Credit::where('seller_id', $request->seller_id)
            ->whereDate('created_at', $request->date)
            ->sum(DB::raw('micro_insurance_percentage * credit_value / 100'));

        // === Calcular real_to_deliver incluyendo los nuevos campos ===
        $realToDeliver = $request->initial_cash +
            ($request->total_income + $request->total_collected + $poliza) -
            ($request->total_expenses + $request->new_credits + $irrecoverableCredits + $total_renewal_disbursed);

        $shortage = 0;
        $surplus = 0;
        $pendingDebt = 0;

        if ($realToDeliver > 0) {
            if ($request->cash_delivered < $realToDeliver) {
                $shortage = $realToDeliver - $request->cash_delivered;
            } else {
                $surplus = $request->cash_delivered - $realToDeliver;
            }
        } else {
            $debtAmount = abs($realToDeliver);

            if ($request->cash_delivered > $debtAmount) {
                $surplus = $request->cash_delivered - $debtAmount;
                $pendingDebt = 0;
            } else {
                $pendingDebt = $debtAmount - $request->cash_delivered;
                $shortage = $pendingDebt;
            }
        }

        $liquidationData = [
            'date' => $request->date,
            'seller_id' => $request->seller_id,
            'collection_target' => $request->collection_target,
            'initial_cash' => $request->initial_cash,
            'base_delivered' => $request->base_delivered,
            'total_collected' => $request->total_collected,
            'total_expenses' => $request->total_expenses,
            'total_income' => $request->total_income,
            'new_credits' => $request->new_credits,
            'real_to_deliver' => $realToDeliver,
            'shortage' => $shortage,
            'surplus' => $surplus,
            'path' => $request->path,
            'cash_delivered' => $request->cash_delivered,
            'status' => 'pending',
            'irrecoverable_credits_amount' => $irrecoverableCredits,
            'renewal_disbursed_total' => $total_renewal_disbursed,
            'poliza' => $poliza,
        ];


        if ($request->has('created_at')) {
            $liquidationData['created_at'] = $request->created_at;
        } else {
            if ($request->filled('timezone')) {
                $liquidationData['created_at'] = Carbon::now($timezone);
                $liquidationData['updated_at'] = Carbon::now($timezone);
            }
        }

        if ($request->has('path')) {
            $imageFile = $request->file('path');
            $imagePath = Helper::uploadFile($imageFile, 'liquidations');
            $liquidationData['path'] = $imagePath;
        }

        $liquidation = Liquidation::create($liquidationData);

        LiquidationAudit::create([
            'liquidation_id' => $liquidation->id,
            'user_id' => $user->id,
            'action' => 'created',
            'changes' => json_encode($liquidation->toArray()),
            'created_at' => Carbon::now($timezone),
        ]);

        // Notificación de sobrante/faltante si está activo en SellerConfig
        $sellerConfig = \App\Models\SellerConfig::where('seller_id', $request->seller_id)->first();
        if ($sellerConfig && $sellerConfig->notify_shortage_surplus) {
            $seller = Seller::find($request->seller_id);
            $admins = \App\Models\User::whereIn('role_id', [1, 2])->get();
            $userToNotify = $seller->user;
            if ($shortage > 0) {
                $message = 'Alerta: El vendedor ' . $seller->user->name . ' tiene un faltante de $' . number_format($shortage, 2) . ' en la liquidación del ' . $request->date . '.';
                $link = '/dashboard/liquidaciones/' . $liquidation->id;
                $data = [
                    'liquidation_id' => $liquidation->id,
                    'seller_id' => $seller->id,
                    'shortage' => $shortage,
                    'date' => $request->date,
                ];
                $userToNotify->notify(new \App\Notifications\GeneralNotification('Alerta de faltante en liquidación', $message, $link, $data));
                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\GeneralNotification('Alerta de faltante en liquidación', $message, $link, $data));
                }
            }
            if ($surplus > 0) {
                $message = 'Alerta: El vendedor ' . $seller->user->name . ' tiene un sobrante de $' . number_format($surplus, 2) . ' en la liquidación del ' . $request->date . '.';
                $link = '/dashboard/liquidaciones/' . $liquidation->id;
                $data = [
                    'liquidation_id' => $liquidation->id,
                    'seller_id' => $seller->id,
                    'surplus' => $surplus,
                    'date' => $request->date,
                ];
                $userToNotify->notify(new \App\Notifications\GeneralNotification('Alerta de sobrante en liquidación', $message, $link, $data));
                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\GeneralNotification('Alerta de sobrante en liquidación', $message, $link, $data));
                }
            }
        }

        if ($user->role_id === 5) {
            $seller = Seller::find($request->seller_id);

            $adminUsers = User::whereIn('role_id', [1, 2])->get();

            foreach ($adminUsers as $adminUser) {
                $adminUser->notify(new GeneralNotification(
                    'Solicitud de liquidación ',
                    'El vendedor ' . $seller->user->name . ' de la ruta ' . $seller->city->country->name . ',' .  $seller->city->name . ' ha creado una nueva liquidación para la fecha ' . $request->date,
                    '/dashboard/liquidaciones',
                    [
                        'country_id' => $seller->city->country->id,
                        'city_id' => $seller->city->id,
                        'seller_id' => $seller->id,
                        'date' => $request->date,
                    ]
                ));
            }
        }

        return response()->json([
            'success' => true,
            'data' => $liquidation,
            'message' => 'Liquidación guardada correctamente'
        ]);
    }

    public function updateLiquidation(UpdateLiquidationRequest $request, $id)
    {
        $user = Auth::user();

        $timezone = $request->input('timezone', 'America/Lima');
        $now = Carbon::now($timezone);
        $liquidation = Liquidation::findOrFail($id);

        $date = $request->date ?? $liquidation->date;
        $sellerId = $request->seller_id ?? $liquidation->seller_id;

        $existingLiquidation = Liquidation::where('seller_id', $sellerId)
            ->where('date', $date)
            ->where('id', '!=', $id)
            ->first();

        if ($existingLiquidation && $user->role_id != 1 && $user->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe otra liquidación para este vendedor en la fecha seleccionada'
            ], 422);
        }

        $pendingExpenses = Expense::where('user_id', $user->id)
            ->whereDate('created_at', $date)
            ->where('status', 'Pendiente')
            ->exists();

        if ($pendingExpenses) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede liquidar porque tienes gastos pendientes de aprobación en la fecha seleccionada'
            ], 422);
        }

        $originalData = $liquidation->getOriginal();

        $initial_cash = $request->has('initial_cash') ? $request->initial_cash : $liquidation->initial_cash;
        $base_delivered = $request->has('base_delivered') ? $request->base_delivered : $liquidation->base_delivered;
        $total_collected = $request->has('total_collected') ? $request->total_collected : $liquidation->total_collected;
        $total_expenses = $request->has('total_expenses') ? $request->total_expenses : $liquidation->total_expenses;
        $new_credits = $request->has('new_credits') ? $request->new_credits : $liquidation->new_credits;
        $total_income = $request->has('total_income') ? $request->total_income : $liquidation->total_income;
        $cash_delivered = $request->has('cash_delivered') ? $request->cash_delivered : $liquidation->cash_delivered;
        $collection_target = $request->has('collection_target') ? $request->collection_target : $liquidation->collection_target;

        // === Calcular créditos irrecuperables ===
        $irrecoverableCredits = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('credits.status', 'Cartera Irrecuperable')
            ->whereDate('credits.updated_at', $date)
            ->where('installments.status', 'Pendiente')
            ->sum('installments.quota_amount');

        // === Calcular renovaciones desembolsadas ===
        $startUTC = Carbon::parse($date, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($date, $timezone)->endOfDay()->setTimezone('UTC');

        $renewalCredits = \App\Models\Credit::where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNotNull('renewed_from_id')
            ->get();

        $total_renewal_disbursed = 0;
        foreach ($renewalCredits as $renewCredit) {
            $oldCredit = \App\Models\Credit::find($renewCredit->renewed_from_id);
            $pendingAmount = 0;
            $oldCreditTotal = 0;
            $oldCreditPaid = 0;
            if ($oldCredit) {
                $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                $oldCreditPaid = \App\Models\Payment::where('credit_id', $oldCredit->id)->sum('amount');
                $pendingAmount = $oldCreditTotal - $oldCreditPaid;
            }
            $netDisbursement = $renewCredit->credit_value - $pendingAmount;
            $total_renewal_disbursed += $netDisbursement;
        }

        // === Calcular valor de póliza ===
        $poliza = \App\Models\Credit::where('seller_id', $sellerId)
            ->whereDate('created_at', $date)
            ->sum(DB::raw('micro_insurance_percentage * credit_value / 100'));

        // === Calcular real_to_deliver incluyendo los nuevos campos ===
        $realToDeliver = $initial_cash +
            $base_delivered +
            ($total_income + $total_collected + $poliza) -
            $total_expenses -
            $new_credits -
            $irrecoverableCredits -
            $total_renewal_disbursed;

        $shortage = 0;
        $surplus = 0;
        $pendingDebt = 0;

        if ($realToDeliver > 0) {
            if ($cash_delivered < $realToDeliver) {
                $shortage = $realToDeliver - $cash_delivered;
            } else {
                $surplus = $cash_delivered - $realToDeliver;
            }
        } else {
            $debtAmount = abs($realToDeliver);
            if ($cash_delivered > $debtAmount) {
                $surplus = $cash_delivered - $debtAmount;
                $pendingDebt = 0;
            } else {
                $pendingDebt = $debtAmount - $cash_delivered;
                $shortage = $pendingDebt;
            }
        }

        $liquidation->update([
            'date' => $date,
            'seller_id' => $sellerId,
            'collection_target' => $collection_target,
            'initial_cash' => $initial_cash,
            'base_delivered' => $base_delivered,
            'total_collected' => $total_collected,
            'total_expenses' => $total_expenses,
            'new_credits' => $new_credits,
            'total_income' => $total_income,
            'real_to_deliver' => $realToDeliver,
            'shortage' => $shortage,
            'surplus' => $surplus,
            'cash_delivered' => $cash_delivered,
            'status' => 'pending',
            'irrecoverable_credits_amount' => $irrecoverableCredits,
            'renewal_disbursed_total' => $total_renewal_disbursed,
            'poliza' => $poliza,
            'updated_at' => $request->has('updated_at') ? Carbon::parse($request->updated_at, $timezone) : $now,
        ]);

        $liquidation->refresh();
        $changedData = $liquidation->getChanges();

        // Notificación de sobrante/faltante si está activo en SellerConfig
        $sellerConfig = \App\Models\SellerConfig::where('seller_id', $sellerId)->first();
        if ($sellerConfig && $sellerConfig->notify_shortage_surplus) {
            $seller = Seller::find($sellerId);
            $admins = \App\Models\User::whereIn('role_id', [1, 2])->get();
            $userToNotify = $seller->user;
            if ($shortage > 0) {
                $message = 'Alerta: El vendedor ' . $seller->user->name . ' tiene un faltante de $' . number_format($shortage, 2) . ' en la liquidación del ' . $date . '.';
                $link = '/dashboard/liquidaciones/' . $liquidation->id;
                $data = [
                    'liquidation_id' => $liquidation->id,
                    'seller_id' => $seller->id,
                    'shortage' => $shortage,
                    'date' => $date,
                ];
                $userToNotify->notify(new \App\Notifications\GeneralNotification('Alerta de faltante en liquidación', $message, $link, $data));
                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\GeneralNotification('Alerta de faltante en liquidación', $message, $link, $data));
                }
            }
            if ($surplus > 0) {
                $message = 'Alerta: El vendedor ' . $seller->user->name . ' tiene un sobrante de $' . number_format($surplus, 2) . ' en la liquidación del ' . $date . '.';
                $link = '/dashboard/liquidaciones/' . $liquidation->id;
                $data = [
                    'liquidation_id' => $liquidation->id,
                    'seller_id' => $seller->id,
                    'surplus' => $surplus,
                    'date' => $date,
                ];
                $userToNotify->notify(new \App\Notifications\GeneralNotification('Alerta de sobrante en liquidación', $message, $link, $data));
                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\GeneralNotification('Alerta de sobrante en liquidación', $message, $link, $data));
                }
            }
        }

        // Registra la auditoría
        LiquidationAudit::create([
            'liquidation_id' => $liquidation->id,
            'user_id' => $user->id,
            'action' => 'updated',
            'changes' => json_encode([
                'before' => $originalData,
                'after' => $liquidation->toArray(),
                'changed_fields' => $changedData,
            ]),
        ]);

        if ($user->role_id === 5) {
            $seller = Seller::find($liquidation->seller_id);

            $adminUsers = User::whereIn('role_id', [1, 2])->get();

            foreach ($adminUsers as $adminUser) {
                $adminUser->notify(new GeneralNotification(
                    'Solicitud de liquidación ',
                    'El vendedor ' . $seller->user->name . ' de la ruta ' . $seller->city->country->name . ',' .  $seller->city->name . ' ha actualizado la liquidación para la fecha ' . $liquidation->date,
                    '/dashboard/liquidaciones',
                    [
                        'country_id' => $seller->city->country->id,
                        'city_id' => $seller->city->id,
                        'seller_id' => $seller->id,
                        'date' => $liquidation->date,
                    ]
                ));
            }
        }

        // Recalcula todas las liquidaciones posteriores
        $this->recalculateNextLiquidations($sellerId, $date);

        return response()->json([
            'success' => true,
            'data' => $liquidation,
            'message' => 'Liquidación cerrada correctamente'
        ]);
    }

    public function reopenRoute(ReopenRouteRequest $request)
    {
        $result = $this->liquidationService->reopenRoute($request->seller_id, $request->date, $request);
        return response()->json($result);
    }

    // Tu función de recalculo debe estar en el mismo controlador:
    public function recalculateLiquidation($sellerId, $date)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($date, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($date, $timezone)->endOfDay()->setTimezone('UTC');

        // Busca la liquidación del vendedor en esa fecha
        $liquidation = Liquidation::where('seller_id', $sellerId)
            ->whereBetween('date', [$startUTC, $endUTC])
            ->first();

        if (!$liquidation) return;

        // 1. Obtener el user_id del vendedor
        $seller = Seller::find($sellerId);
        $userId = $seller ? $seller->user_id : null;

        // 2. Recalcula los totales actuales desde la BD
        $totalExpenses = $userId
            ? Expense::where('user_id', $userId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->sum('value')
            : 0;

        $totalIncome = $userId
            ? Income::where('user_id', $userId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->sum('value')
            : 0;

        $newCredits = Credit::where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNull('renewed_from_id')
            ->sum('credit_value');

        $totalCollected = Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->whereBetween('payments.created_at', [$startUTC, $endUTC])
            ->sum('payments.amount');

        $renewalCredits = DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNotNull('renewed_from_id')
            ->get();

        $total_renewal_disbursed = 0;
        $total_pending_absorbed = 0;

        foreach ($renewalCredits as $renewCredit) {
            $oldCredit = DB::table('credits')->where('id', $renewCredit->renewed_from_id)->first();

            $pendingAmount = 0;
            if ($oldCredit) {
                $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                $oldCreditPaid = DB::table('payments')->where('credit_id', $oldCredit->id)->sum('amount');
                $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                $total_pending_absorbed += $pendingAmount;
            }

            $netDisbursement = $renewCredit->credit_value - $pendingAmount;
            $total_renewal_disbursed += $netDisbursement;
        }

        $realToDeliver = $liquidation->initial_cash
            + $liquidation->base_delivered
            + ($totalIncome + $totalCollected)
            - $totalExpenses
            - $newCredits
            - $total_renewal_disbursed;

        $cashDelivered = $liquidation->cash_delivered;
        $shortage = 0;
        $surplus = 0;
        if ($realToDeliver > 0) {
            if ($cashDelivered < $realToDeliver) {
                $shortage = $realToDeliver - $cashDelivered;
            } else {
                $surplus = $cashDelivered - $realToDeliver;
            }
        } else {
            $debtAmount = abs($realToDeliver);
            if ($cashDelivered > $debtAmount) {
                $surplus = $cashDelivered - $debtAmount;
            } else {
                $shortage = $debtAmount - $cashDelivered;
            }
        }

        // Actualiza la liquidación con todos los campos recalculados
        $liquidation->update([
            'total_expenses'      => $totalExpenses,
            'new_credits'         => $newCredits,
            'total_income'        => $totalIncome,
            'total_collected'     => $totalCollected,
            'real_to_deliver'     => $realToDeliver,
            'shortage'            => $shortage,
            'surplus'             => $surplus,
        ]);
    }
    public function approveLiquidation($id)
    {
        try {
            return $this->liquidationService->approve($id);
        } catch (\Exception $e) {
            \Log::error("Error al aprobar liquidación: " . $e->getMessage());
            return $this->errorResponse('Error al aprobar la liquidación', 500);
        }
    }

    public function approveMultipleLiquidations(Request $request)
    {
        try {
            $ids = $request->input('ids');
            return $this->liquidationService->approveMultiple($ids);
        } catch (\Exception $e) {
            \Log::error("Error al aprobar liquidaciones múltiples: " . $e->getMessage());
            return $this->errorResponse('Error al aprobar las liquidaciones', 500);
        }
    }

    public function annulBase(Request $request, $id)
    {
        $liquidation = Liquidation::findOrFail($id);

        try {
            DB::beginTransaction();

            // Anula la base
            $liquidation->update([
                'base_delivered' => 0,
            ]);

            // Recalcula todas las liquidaciones posteriores al día de esta liquidación
            $this->recalculateNextLiquidations($liquidation->seller_id, $liquidation->date);

            DB::commit();

            // Refresca los datos
            $liquidation->refresh();

            return response()->json([
                'success' => true,
                'data' => $liquidation,
                'message' => 'Base anulada y liquidación recalculada correctamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al anular la base: ' . $e->getMessage()
            ], 500);
        }
    }

    protected function recalculateNextLiquidations($sellerId, $fromDate)
    {
        $timezone = 'America/Lima';

        // Busca todas las liquidaciones posteriores
        $liquidations = Liquidation::where('seller_id', $sellerId)
            ->where('date', '>', $fromDate)
            ->orderBy('date', 'asc')
            ->get();

        // Busca la liquidación base (la que fue modificada)
        $baseLiquidation = Liquidation::where('seller_id', $sellerId)
            ->where('date', $fromDate)
            ->first();

        $previousRealToDeliver = $baseLiquidation ? $baseLiquidation->real_to_deliver : 0;

        foreach ($liquidations as $liquidation) {
            $initial_cash = $previousRealToDeliver;

            // Recalcula los montos de ese día con la lógica de tu store/update
            // Si tienes otros campos que se deben recalcular, ajústalo aquí
            $realToDeliver = $initial_cash +
                ($liquidation->total_income + $liquidation->total_collected + $liquidation->poliza)
                - ($liquidation->total_expenses + $liquidation->new_credits + $liquidation->irrecoverable_credits_amount + $liquidation->renewal_disbursed_total);

            $shortage = 0;
            $surplus = 0;
            $pendingDebt = 0;

            if ($realToDeliver > 0) {
                if ($liquidation->cash_delivered < $realToDeliver) {
                    $shortage = $realToDeliver - $liquidation->cash_delivered;
                } else {
                    $surplus = $liquidation->cash_delivered - $realToDeliver;
                }
            } else {
                $debtAmount = abs($realToDeliver);
                if ($liquidation->cash_delivered > $debtAmount) {
                    $surplus = $liquidation->cash_delivered - $debtAmount;
                    $pendingDebt = 0;
                } else {
                    $pendingDebt = $debtAmount - $liquidation->cash_delivered;
                    $shortage = $pendingDebt;
                }
            }

            // Actualiza la liquidación
            $liquidation->update([
                'initial_cash' => $initial_cash,
                'real_to_deliver' => $realToDeliver,
                'shortage' => $shortage,
                'surplus' => $surplus,
            ]);

            // El saldo final de esta liquidación será el saldo inicial de la siguiente
            $previousRealToDeliver = $realToDeliver;
        }
    }

    private function checkAuthorization($user, $sellerId)
    {
        // Admins y supervisores pueden acceder a cualquier vendedor
        if (in_array($user->role_id, [1, 2])) {
            return true;
        }

        // Vendedores solo pueden acceder a sus propios datos
        if ($user->role_id == 5) {
            $seller = Seller::where('user_id', $user->id)->first();
            return $seller && $seller->id == $sellerId;
        }

        return false;
    }

    public function getLiquidationData($sellerId, $date, Request $request)
    {
        $user = Auth::user();

        // Zona horaria Venezuela
        $timezone = $request->query('timezone', 'America/Lima');
        $start = Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay()->setTimezone('UTC');
        $end = Carbon::createFromFormat('Y-m-d', $date, $timezone)->endOfDay()->setTimezone('UTC');
        $todayDate = Carbon::now($timezone)->toDateString();

        \Log::debug("Solicitud de datos de liquidación para vendedor $sellerId en fecha $date por usuario {$user->id} ({$user->role_id})");

        // 1. Verificar si ya existe liquidación para esta fecha
        $existingLiquidation = Liquidation::where('seller_id', $sellerId)
            ->whereDate('date', $date)
            ->first();
        \Log::debug("Verificando liquidación para vendedor $sellerId en fecha desde $start hasta $end");
        if ($existingLiquidation) {
            \Log::debug("liquidation existente: " . ($existingLiquidation ? 'sí' : 'no'));

            /*  \Log::debug('Datos de la liquidación encontrada: ' . json_encode($existingLiquidation->toArray())); */
            $updatedLiquidation = Liquidation::where('seller_id', $sellerId)
                ->whereDate('date', $date)
                ->first();
            return $this->formatLiquidationResponse($updatedLiquidation, true, $timezone);
        }

        // 2. Obtener datos del endpoint dailyPaymentTotals
        $dailyTotals = $this->getDailyTotals($sellerId, $date, $user, $timezone);


        // 3. Obtener última liquidación para saldo inicial
        $lastLiquidation = Liquidation::where('seller_id', $sellerId)
            ->where('date', '<', $date)
            ->orderBy('date', 'desc')
            ->first();

        $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

        $irrecoverableCredits = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('credits.status', 'Cartera Irrecuperable')
            ->whereDate('credits.updated_at', $todayDate)
            ->where('installments.status', 'Pendiente')
            ->sum('installments.quota_amount');

        /*  Log::info('INcome: ' . $dailyTotals['total_income']);
        Log::info('Initial Cash: ' . $initialCash); */
        // 4. Calcular valor real a entregar
        $realToDeliver = $initialCash
            + ($dailyTotals['total_income'] + $dailyTotals['collected_total'] + $dailyTotals['poliza'])
            - ($dailyTotals['created_credits_value']
                + $dailyTotals['total_renewal_disbursed']
                + $dailyTotals['total_expenses']
                + $irrecoverableCredits);

        $cashCollection = ($dailyTotals['total_income'] + $dailyTotals['collected_total'] + $dailyTotals['poliza'])
            - ($dailyTotals['created_credits_value']
                + $dailyTotals['total_renewal_disbursed']
                + $dailyTotals['total_expenses']
                + $irrecoverableCredits);

        // 5. Estructurar respuesta completa
        return [
            'collection_target' => $dailyTotals['daily_goal'],
            'initial_cash' => $initialCash,
            'cash_collection' => $cashCollection,
            'base_delivered' => $dailyTotals['base_value'],
            'total_collected' => $dailyTotals['collected_total'],
            'total_expenses' => $dailyTotals['total_expenses'],
            'total_income' => $dailyTotals['total_income'],
            'new_credits' => $dailyTotals['created_credits_value'],
            'real_to_deliver' => $realToDeliver,
            'date' => $date,
            'seller_id' => $sellerId,
            'cash' => $dailyTotals['cash'],
            'transfer' => $dailyTotals['transfer'],
            'expected_total' => $dailyTotals['expected_total'],
            'poliza' => $dailyTotals['poliza'],
            'current_balance' => $dailyTotals['current_balance'],
            'total_clients' => $dailyTotals['total_clients'],
            'existing_liquidation' => null,
            'last_liquidation' => $lastLiquidation ? $this->formatLiquidationDetails($lastLiquidation) : null,
            'is_new' => true,
            'liquidation_start_date' => $dailyTotals['liquidation_start_date'],
            'total_crossed_credits' => $dailyTotals['total_crossed_credits'] ?? 0,
            'total_renewal_disbursed' => $dailyTotals['total_renewal_disbursed'] ?? 0,
        ];
    }

    protected function getDailyTotals($sellerId, $date, $user, $timezone = 'America/Lima')
    {
        $targetDate = Carbon::parse($date, $timezone);
        $formattedDate = $targetDate->format('Y-m-d');

        $startUTC = $targetDate->copy()->startOfDay()->timezone('UTC');
        $endUTC = $targetDate->copy()->endOfDay()->timezone('UTC');

        \Log::info('[getDailyTotals] sellerId: ' . $sellerId . ', date: ' . $date . ', timezone: ' . $timezone);
        \Log::info('[getDailyTotals] startUTC: ' . $startUTC . ', endUTC: ' . $endUTC);

        $query = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(
                'payments.payment_method',
                DB::raw('SUM(payments.amount) as total')
            )
            ->whereDate('payments.created_at', $date)
            ->where('credits.seller_id', $sellerId)
            ->where('payments.status', 'Aprobado')
            ->groupBy('payments.payment_method');

        $firstPaymentQuery = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(DB::raw('MIN(payments.created_at) as first_payment_date'))
            ->whereDate('payments.created_at', $date);

        if ($sellerId) {
            $firstPaymentQuery->where('credits.seller_id', $sellerId);
        }

        $firstPaymentResult = $firstPaymentQuery->first();
        $firstPaymentDate = $firstPaymentResult ? $firstPaymentResult->first_payment_date : null;

        $paymentResults = $query->get();

        \Log::info('[getDailyTotals] paymentResults: ', $paymentResults->toArray());

        $totals = [
            'cash' => 0,
            'transfer' => 0,
            'collected_total' => 0,
            'base_value' => 0,
            'liquidation_start_date' => $firstPaymentDate
        ];

        foreach ($paymentResults as $result) {
            $amount = (float)$result->total;
            if ($result->payment_method === 'Efectivo') {
                $totals['cash'] = $amount;
            } elseif ($result->payment_method === 'Transferencia') {
                $totals['transfer'] = $amount;
            }
            $totals['collected_total'] += $amount;
        }

        $totals['expected_total'] = (float)DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('installments.due_date', $formattedDate)
            ->sum('installments.quota_amount');

        $credits = DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereDate('created_at', $date)
            ->whereNull('renewed_from_id')
            ->whereNull('deleted_at')
            ->whereNull('unification_reason')
            ->select([
                DB::raw('COALESCE(SUM(credit_value), 0) as value'),
                DB::raw('COALESCE(SUM(
            CASE 
                WHEN total_interest IS NOT NULL AND total_interest > 0 
                THEN credit_value * (total_interest / 100)
                ELSE 0
            END
        ), 0) as interest')
            ])
            ->first();

        \Log::info('[getDailyTotals] credits: ', (array)$credits);

        /* Log::info('Créditos creados en ' . $targetDate->format('Y-m-d') . ':', [
            'query' => DB::table('credits')
                ->where('seller_id', $sellerId)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->toSql(),
            'bindings' => DB::table('credits')
                ->where('seller_id', $sellerId)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->getBindings(),
            'result' => $credits
        ]); */

        $totals['created_credits_value'] = (float)$credits->value;
        $totals['created_credits_interest'] = (float)$credits->interest;

        $totals['total_expenses'] = (float)Expense::where('user_id', $user->id)
            ->whereDate('created_at', $date)
            ->where('status', 'Aprobado')
            ->sum('value');

        $totals['total_income'] = (float)Income::where('user_id', $user->id)
            ->whereDate('created_at', $date)
            ->sum('value');

        $totals['total_clients'] = (int)DB::table('clients')
            ->whereExists(function ($query) use ($sellerId) {
                $query->select(DB::raw(1))
                    ->from('credits')
                    ->whereColumn('credits.client_id', 'clients.id')
                    ->where('credits.seller_id', $sellerId);
            })
            ->count();

        // Calcular saldos
        $totals['daily_goal'] = $totals['expected_total'];
        $totals['current_balance'] = $totals['collected_total'] - $totals['total_expenses'];

        /*  Log::info($totals['expected_total']); */

        $renewalCredits = DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereDate('created_at', $date)
            ->whereNotNull('renewed_from_id')
            ->get();


        $detalles_renovaciones = [];
        $total_renewal_disbursed = 0;
        $total_pending_absorbed = 0;

        foreach ($renewalCredits as $renewCredit) {
            $oldCredit = DB::table('credits')->where('id', $renewCredit->renewed_from_id)->first();

            $pendingAmount = 0;
            $oldCreditTotal = 0;
            $oldCreditPaid = 0;
            if ($oldCredit) {
                $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                $oldCreditPaid = DB::table('payments')->where('credit_id', $oldCredit->id)->sum('amount');
                $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                $total_pending_absorbed += $pendingAmount;
            }


            $netDisbursement = $renewCredit->credit_value - $pendingAmount;
            $total_renewal_disbursed += $netDisbursement;
        }

        $totals['total_renewal_disbursed'] = $total_renewal_disbursed;
        $totals['total_crossed_credits'] = $total_pending_absorbed;

        // === Calcular valor de póliza ===
        $totals['poliza'] = (float)DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereDate('created_at', $date)
            ->sum(DB::raw('micro_insurance_percentage * credit_value / 100'));

        \Log::info('[getDailyTotals] poliza: ', ['poliza' => $totals['poliza']]);

        \Log::info('[getDailyTotals] totals before return: ', ['totals' => $totals]);

        return $totals;
    }

    public function getBySeller(Request $request, $sellerId)
    {
        try {
            $perPage = $request->input('per_page', 20);


            $response = $this->liquidationService->getLiquidationsBySeller(
                $sellerId,
                $request,
            );

            return $response;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener liquidaciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtiene estadísticas de un vendedor
     *
     * @param int $sellerId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSellerStats($sellerId)
    {
        try {
            $response = $this->liquidationService->getSellerStats($sellerId);
            return $response;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Muestra una liquidación específica
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $liquidation = Liquidation::with('seller')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $liquidation
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Liquidación no encontrada'
            ], 404);
        }
    }

    // Método para formatear respuesta de liquidación existente
    protected function formatLiquidationResponse($liquidation, $isExisting = false, $timezone = 'America/Lima')
    {
        $user = Auth::user();
        $firstPaymentDate = null;
        if ($isExisting) {
            $firstPaymentQuery = DB::table('payments')
                ->join('credits', 'payments.credit_id', '=', 'credits.id')
                ->select('payments.payment_date', 'payments.created_at') // 👈 aquí
                ->whereDate('payments.created_at', $liquidation->date)
                ->where('credits.seller_id', $liquidation->seller_id)
                ->orderBy('payments.created_at', 'asc')
                ->first();


            if ($firstPaymentQuery) {
                $firstPaymentDate = $firstPaymentQuery->created_at;
            }
        }

        $dailyTotals = $this->getDailyTotals($liquidation->seller_id, $liquidation->date, $user, $timezone);

        return [
            'collection_target' => $liquidation->collection_target,
            'initial_cash' => $liquidation->initial_cash,
            'base_delivered' => $liquidation->base_delivered,
            'total_collected' => $liquidation->total_collected,
            'total_expenses' => $liquidation->total_expenses,
            'total_income' => $liquidation->total_income,
            'new_credits' => $liquidation->new_credits,
            'real_to_deliver' => $liquidation->real_to_deliver,
            'date' => $liquidation->date,
            'seller_id' => $liquidation->seller_id,
            'existing_liquidation' => $isExisting ? $this->formatLiquidationDetails($liquidation) : null,
            'last_liquidation' => $this->getPreviousLiquidation($liquidation->seller_id, $liquidation->date),
            'is_new' => false,
            'liquidation_start_date' => $firstPaymentDate,
            'total_crossed_credits' => $dailyTotals['total_crossed_credits'],
            'total_renewal_disbursed' => $dailyTotals['total_renewal_disbursed'],
            'poliza' => $liquidation->poliza

        ];
    }

    // Método para obtener liquidación anterior
    protected function getPreviousLiquidation($sellerId, $date)
    {
        $lastLiquidation = Liquidation::where('seller_id', $sellerId)
            ->where('date', '<', $date)
            ->orderBy('date', 'desc')
            ->first();

        return $lastLiquidation ? $this->formatLiquidationDetails($lastLiquidation) : null;
    }

    // Método para formatear detalles de liquidación
    protected function formatLiquidationDetails($liquidation)
    {
        return [
            'id' => $liquidation->id,
            'date' => $liquidation->date,
            'real_to_deliver' => $liquidation->real_to_deliver,
            'total_collected' => $liquidation->total_collected,
            'total_expenses' => $liquidation->total_expenses,
            'new_credits' => $liquidation->new_credits,
            'base_delivered' => $liquidation->base_delivered,
            'shortage' => $liquidation->shortage,
            'surplus' => $liquidation->surplus,
            'cash_delivered' => $liquidation->cash_delivered,
            'status' => $liquidation->status,
            'created_at' => $liquidation->created_at,
            'poliza' => $liquidation->poliza

        ];
    }
    public function getLiquidationHistory(LiquidationHistoryRequest $request)
    {
        $user = Auth::user();
        $sellerId = $request->seller_id;
        if (!$this->checkAuthorization($user, $sellerId))            return response()->json(['error' => 'Unauthorized'], 403);
        $result = $this->liquidationService->getLiquidationHistory($sellerId, $request->start_date, $request->end_date);
        return response()->json(['success' => true, 'data' => $result]);
    }
    public function getAccumulatedByCity(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $results = $this->liquidationService->getAccumulatedByCity($startDate, $endDate);

            return response()->json([
                'success' => true,
                'message' => 'Datos obtenidos exitosamente',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los datos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAccumulatedByCityWithSellers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'city_id' => 'required|exists:cities,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $cityId = $request->input('city_id');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            // Llamar al nuevo servicio
            $results = $this->liquidationService->getAccumulatedBySellerInCity($cityId, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'message' => 'Datos obtenidos exitosamente',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los datos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getSellersSummaryByCity(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $cityId = $request->input('city_id');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $results = $this->liquidationService->getAccumulatedBySellersInCity($cityId, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'message' => 'Resumen por vendedores obtenido exitosamente',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el resumen por vendedores',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getSellerLiquidationsDetail(Request $request, $sellerId)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $liquidations = Liquidation::with(['seller', 'seller.user'])
                ->where('seller_id', $sellerId)
                ->whereBetween('date', [$startDate, $endDate])
                ->orderBy('date', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Liquidaciones del vendedor obtenidas exitosamente',
                'data' => $liquidations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las liquidaciones del vendedor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Descarga el reporte de liquidación en PDF o Excel
     */
    public function downloadReport($id, Request $request)
    {
        $format = $request->get('format', 'pdf');
        $timezone = $request->get('timezone', 'America/Lima');
        return $this->liquidationService->downloadLiquidationReport($id, $format, $timezone);
    }

    /**
     * Devuelve la fecha de la primera liquidación aprobada de cada vendedor (con ciudad y país)
     */
    public function getFirstApprovedLiquidationBySeller()
    {
        $result = $this->liquidationService->getFirstApprovedLiquidationBySeller();
        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

    /**
     * Devuelve el detalle de una liquidación con totalizadores y listados paginados
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLiquidationDetail($id, Request $request)
    {
        $response = $this->liquidationService->getLiquidationDetail($id, $request);
        return response()->json($response);
    }
}
