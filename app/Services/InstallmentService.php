<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\Credit;
use App\Models\Payment;
use App\Models\PaymentInstallment;
use App\Models\Liquidation;
use App\Models\User;
use Carbon\Carbon;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class InstallmentService
{
    use ApiResponse;
    public function index()
    {
        try {
            return $this->successResponse([
                'success' => true,
                'message' => 'Cuotas obtenidas correctamente',
                'data' => Installment::all()
            ]);
        } catch (Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show($creditId)
    {
        try {

            $installments = Installment::where('credit_id', $creditId)->get();

            if ($installments->isEmpty()) {
                return $this->errorResponse('No se encontraron cuotas para el crédito especificado', 404);
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Cuota obtenida correctamente',
                'data' => $installments
            ]);
        } catch (Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get installments for a seller with liquidation information
     */
    public function getInstallmentsBySeller($sellerId, $perPage = 20, $search = '', $status = null)
    {
        try {
            $query = Installment::with([
                'credit' => function($q) {
                    $q->with(['client', 'seller']);
                },
                'payments' => function($q) {
                    $q->with('payment');
                }
            ])
            ->whereHas('credit', function($q) use ($sellerId) {
                $q->where('seller_id', $sellerId);
            })
            ->orderBy('id', 'DESC');

            // Search filter
            if (!empty($search)) {
                $query->whereHas('credit.client', function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('dni', 'like', "%{$search}%");
                });
            }

            // Status filter
            if ($status) {
                $query->where('status', $status);
            }

            $installments = $query->paginate($perPage);

            // Add liquidation info to each installment
            $installments->getCollection()->transform(function($installment) {
                $liquidationInfo = $this->getLiquidationForInstallment($installment);
                $installment->liquidation_number = $liquidationInfo['liquidation_id'] ?? null;
                $installment->liquidation_date = $liquidationInfo['liquidation_date'] ?? null;
                return $installment;
            });

            return response()->json([
                'success' => true,
                'data' => $installments->items(),
                'pagination' => [
                    'current_page' => $installments->currentPage(),
                    'last_page' => $installments->lastPage(),
                    'per_page' => $installments->perPage(),
                    'total' => $installments->total()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching installments by seller: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las cuotas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get liquidation information for an installment
     */
    private function getLiquidationForInstallment($installment)
    {
        // Find payments for this installment
        $paymentInstallments = PaymentInstallment::where('installment_id', $installment->id)->get();
        
        if ($paymentInstallments->isEmpty()) {
            return [];
        }

        // Get the payment with business_date
        $paymentIds = $paymentInstallments->pluck('payment_id');
        $payment = Payment::whereIn('id', $paymentIds)
            ->whereNotNull('business_date')
            ->first();

        if (!$payment) {
            return [];
        }

        // Find the liquidation for this business_date and seller
        $liquidation = Liquidation::where('date', $payment->business_date)
            ->where('seller_id', $installment->credit->seller_id)
            ->first();

        if (!$liquidation) {
            return [];
        }

        return [
            'liquidation_id' => $liquidation->id,
            'liquidation_date' => $liquidation->date
        ];
    }

    /**
     * Simulate deletion of an installment
     */
    public function simulateDelete($installmentId)
    {
        try {
            $installment = Installment::with(['credit.seller', 'payments'])->find($installmentId);
            
            if (!$installment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cuota no encontrada'
                ], 404);
            }

            // Get affected liquidations directly linked to payments
            $directAffectedLiquidations = $this->getAffectedLiquidations($installment);
            
            // We need to simulate impact on these AND all subsequent liquidations
            // 1. Calculate the Delta per date
            $deltasByDate = [];
            foreach ($directAffectedLiquidations as $liq) {
                $paidAmount = $this->getPaidAmountForInstallment($installment, $liq->date);
                if ($paidAmount > 0) {
                    $deltasByDate[$liq->date->toDateString()] = -$paidAmount; // Negative because we are deleting
                }
            }
            
            if (empty($deltasByDate)) {
                 return response()->json([
                    'success' => true,
                    'data' => [
                        'installment' => $installment,
                        'affected_liquidations' => []
                    ]
                ]);
            }

            // 2. Load ALL liquidations from the earliest affected date onwards
            $tz = 'America/Lima';
            $today = Carbon::now($tz)->toDateString();
            $earliestDate = min(array_keys($deltasByDate));
            
            $liquidations = Liquidation::where('seller_id', $installment->credit->seller_id)
                ->where('date', '>=', $earliestDate)
                ->orderBy('date', 'asc')
                ->get();

            $liquidationDates = $liquidations->pluck('date')->map(fn($d) => $d->toDateString())->toArray();
            $allDates = array_unique(array_merge($liquidationDates, array_keys($deltasByDate)));
            sort($allDates);

            $simulationData = [];
            $runningCashDelta = 0;
            $liquidationService = app(\App\Services\LiquidationService::class);

            foreach ($allDates as $dateStr) {
                $liquidation = $liquidations->where('date', $dateStr)->first();
                $isVirtual = false;

                if (!$liquidation) {
                    if ($dateStr !== $today) continue;
                    
                    $dynamic = $liquidationService->getLiquidationData($installment->credit->seller_id, $today, $installment->credit->seller->user_id, $tz);
                    $liquidation = (object)[
                        'id' => null,
                        'date' => Carbon::parse($dateStr),
                        'total_collected' => $dynamic['total_collected'] ?? 0,
                        'real_to_deliver' => $dynamic['real_to_deliver'] ?? 0,
                        'initial_cash' => $dynamic['initial_cash'] ?? 0,
                        'new_credits' => $dynamic['new_credits'] ?? 0,
                        'poliza' => $dynamic['poliza'] ?? 0,
                        'total_expenses' => $dynamic['total_expenses'] ?? 0,
                        'shortage' => 0,
                        'surplus' => 0,
                        'renewal_disbursed_total' => $dynamic['total_renewal_disbursed'] ?? 0,
                        'total_pending_absorbed' => $dynamic['total_pending_absorbed'] ?? 0,
                        'base_delivered' => $dynamic['base_delivered'] ?? 0,
                        'cash_delivered' => 0 // For virtual, we assume 0 delivered yet
                    ];
                    $isVirtual = true;
                }

                $dayDirectDelta = $deltasByDate[$dateStr] ?? 0;
                
                $currentValues = [
                    'id' => $liquidation->id,
                    'date' => $liquidation->date->toDateString(),
                    'total_collected' => floatval($liquidation->total_collected),
                    'real_to_deliver' => floatval($liquidation->real_to_deliver),
                    'shortage' => floatval($liquidation->shortage),
                    'surplus' => floatval($liquidation->surplus),
                    'initial_cash' => floatval($liquidation->initial_cash)
                ];

                $newInitialCash = floatval($liquidation->initial_cash) + $runningCashDelta;
                $newTotalCollected = floatval($liquidation->total_collected) + $dayDirectDelta;
                
                $newRealToDeliver = floatval($liquidation->real_to_deliver) + ($newInitialCash - floatval($liquidation->initial_cash)) + ($newTotalCollected - floatval($liquidation->total_collected));
                
                $newShortage = 0;
                $newSurplus = 0;
                $diff = floatval($liquidation->cash_delivered ?? 0) - $newRealToDeliver;
                if ($diff < 0) {
                    $newShortage = abs($diff);
                } else {
                    $newSurplus = $diff;
                }

                $newValues = [
                    'total_collected' => $newTotalCollected,
                    'real_to_deliver' => $newRealToDeliver,
                    'initial_cash' => $newInitialCash,
                    'shortage' => $newShortage,
                    'surplus' => $newSurplus
                ];

                if ($dayDirectDelta != 0 || $runningCashDelta != 0) {
                     $simulationData[] = [
                        'liquidation' => $currentValues,
                        'changes' => $newValues,
                        'installment_impact' => abs($dayDirectDelta),
                        'type' => $isVirtual ? 'Liquidación en curso' : 'Simulación de Eliminación'
                    ];
                }
                
                $runningCashDelta = ($newRealToDeliver - floatval($liquidation->real_to_deliver));
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'installment' => $installment,
                    'affected_liquidations' => $simulationData
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error simulating delete: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al simular eliminación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an installment and recalculate liquidations
     */
        public function deleteInstallment($installmentId, $password)
    {
        try {
            // Verify password
            $user = auth()->user();
            if (!$user || !Hash::check($password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contraseña incorrecta'
                ], 401);
            }

            $installment = Installment::with(['credit.seller', 'payments.payment'])->find($installmentId);
            
            if (!$installment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cuota no encontrada'
                ], 404);
            }

            // Get affected liquidations before undoing to know which ones to recalculate
            $affectedLiquidations = $this->getAffectedLiquidations($installment);
            $earliestDate = $affectedLiquidations->min('date');

            DB::beginTransaction();
            try {
                $totalRevertedAmount = 0;
                $sellerId = $installment->credit->seller_id;
                $credit = $installment->credit;

                // Process each PaymentInstallment (PI)
                foreach ($installment->payments as $pi) {
                    $amountToRevert = $pi->applied_amount;
                    $totalRevertedAmount += $amountToRevert;
                    
                    $payment = $pi->payment;
                    if ($payment) {
                        // Subtract from parent payment
                        $payment->amount -= $amountToRevert;
                        if ($payment->amount <= 0.01) {
                            $payment->delete(); // Soft delete if fully undone
                        } else {
                            $payment->save();
                        }
                    }
                    
                    // Audit deletion
                    $pi->deleted_by = $user->id;
                    $pi->save();
                    $pi->delete(); // Soft delete link
                }

                // Restore Installment State
                $installment->status = 'Pendiente';
                $installment->paid_amount = 0;
                $installment->save();

                // Update Credit Balance
                if ($credit) {
                    $credit->remaining_amount += $totalRevertedAmount;
                    // If credit was 'Pagado', restore it to 'Vigente'
                    if ($credit->status === 'Pagado') {
                        $credit->status = 'Vigente';
                    }
                    $credit->save();
                }

                // Recalculate ALL liquidations via service
                if ($earliestDate) {
                    $liquidationService = app(\App\Services\LiquidationService::class);
                    
                    // Ensure the earliest liquidation exists for audit
                    $mainLiquidation = $liquidationService->getOrCreateLiquidation($sellerId, $earliestDate->toDateString());
                    $oldRealToDeliver = $mainLiquidation ? floatval($mainLiquidation->real_to_deliver) : 0;

                    $affectedLiqs = Liquidation::where('seller_id', $sellerId)
                        ->where('date', '>=', $earliestDate)
                        ->orderBy('date', 'asc')
                        ->get();

                    foreach ($affectedLiqs as $liq) {
                        $liquidationService->recalculateLiquidation($liq->seller_id, $liq->date->toDateString());
                    }
                    $liquidationService->recalculateNextLiquidations($sellerId, $earliestDate->toDateString());

                    // Re-fetch to get new value
                    $updatedLiquidation = Liquidation::where('seller_id', $sellerId)
                        ->where('date', $earliestDate)
                        ->first();
                    $newRealToDeliver = $updatedLiquidation ? floatval($updatedLiquidation->real_to_deliver) : 0;
                    
                    if ($updatedLiquidation) {
                        \App\Models\LiquidationAudit::create([
                            'liquidation_id' => $updatedLiquidation->id,
                            'user_id' => $user->id,
                            'action' => 'reverted_installment_payment',
                            'changes' => [
                                'description' => "Reversión de Pago Cuota #{$installment->quota_number} - Crédito #{$credit->id} - Monto: {$totalRevertedAmount}",
                                'credit_id' => $credit->id,
                                'quota_number' => $installment->quota_number,
                                'amount' => floatval($totalRevertedAmount),
                                'old_real_to_deliver' => $oldRealToDeliver,
                                'new_real_to_deliver' => $newRealToDeliver,
                                'impact' => $newRealToDeliver - $oldRealToDeliver
                            ]
                        ]);
                    }
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Pago de cuota revertido correctamente. La cuota ahora está Pendiente y los saldos han sido ajustados.',
                    'affected_liquidations' => $affectedLiquidations->pluck('id')
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error reversing installment payment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al revertir pago: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Get affected liquidations for an installment
     */
    private function getAffectedLiquidations($installment)
    {
        $affectedDates = [];
        
        // Get payment_installments for this installment
        $paymentInstallments = PaymentInstallment::where('installment_id', $installment->id)->get();
        
        foreach ($paymentInstallments as $pi) {
            $payment = Payment::find($pi->payment_id);
            if ($payment && $payment->business_date) {
                $affectedDates[] = $payment->business_date;
            }
        }

        if (empty($affectedDates)) {
            return collect([]);
        }

        return Liquidation::whereIn('date', $affectedDates)
            ->where('seller_id', $installment->credit->seller_id)
            ->get();
    }

    /**
     * Get paid amount for installment on a specific date
     */
    private function getPaidAmountForInstallment($installment, $date)
    {
        $paymentInstallments = PaymentInstallment::where('installment_id', $installment->id)->get();
        $total = 0;
        
        // Ensure date is string Y-m-d
        $targetDate = $date instanceof Carbon ? $date->toDateString() : substr($date, 0, 10);

        foreach ($paymentInstallments as $pi) {
            $payment = Payment::where('id', $pi->payment_id)->first();
            
            // Fix: Compare business_date properly
            if ($payment && $payment->business_date && substr($payment->business_date, 0, 10) === $targetDate) {
                $total += $pi->applied_amount;
            }
        }

        return $total;
    }

    /**
     * Calculate new real_to_deliver after removing payment
     */
    private function calculateNewRealToDeliver($liquidation, $removedAmount)
    {
        // real_to_deliver = initial_cash + total_collected - base_delivered
        $newTotalCollected = $liquidation->total_collected - $removedAmount;
        return $liquidation->initial_cash + $newTotalCollected - $liquidation->base_delivered;
    }

    /**
     * Recalculate a liquidation after installment deletion
     */
    private function recalculateLiquidation($liquidation)
    {
        // Get all payments for this liquidation date and seller
        $payments = Payment::where('business_date', $liquidation->date)
            ->whereHas('credit', function($q) use ($liquidation) {
                $q->where('seller_id', $liquidation->seller_id);
            })
            ->get();

        $totalCollected = $payments->sum('amount');
        
        $liquidation->total_collected = $totalCollected;
        $liquidation->real_to_deliver = $liquidation->initial_cash + $totalCollected - $liquidation->base_delivered;
        
        // Recalculate shortage/surplus
        $difference = $liquidation->cash_delivered - $liquidation->real_to_deliver;
        if ($difference < 0) {
            $liquidation->shortage = abs($difference);
            $liquidation->surplus = 0;
        } else {
            $liquidation->surplus = $difference;
            $liquidation->shortage = 0;
        }

        $liquidation->save();
    }

    /**
     * Get installments for a specific credit with liquidation information
     */
    public function getCreditInstallments($creditId)
    {
        try {
            $installments = Installment::with([
                'credit' => function($q) {
                    $q->with(['client', 'seller']);
                },
                'payments' => function($q) {
                    $q->with('payment');
                }
            ])
            ->where('credit_id', $creditId)
            ->orderBy('quota_number', 'ASC')
            ->get();

            // Add liquidation info to each installment
            $installments->transform(function($installment) {
                $liquidationInfo = $this->getLiquidationForInstallment($installment);
                $installment->liquidation_number = $liquidationInfo['liquidation_id'] ?? null;
                $installment->liquidation_date = $liquidationInfo['liquidation_date'] ?? null;
                return $installment;
            });

            return response()->json([
                'success' => true,
                'data' => $installments
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching credit installments: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las cuotas: ' . $e->getMessage()
            ], 500);
        }
    }
}
