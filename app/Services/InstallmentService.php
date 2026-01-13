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
            $earliestDate = min(array_keys($deltasByDate));
            $allFutureLiquidations = Liquidation::where('seller_id', $installment->credit->seller_id)
                ->where('date', '>=', $earliestDate)
                ->orderBy('date', 'asc')
                ->get();

            $simulationData = [];
            $runningCashDelta = 0;

            foreach ($allFutureLiquidations as $liquidation) {
                $dateStr = $liquidation->date->toDateString();
                
                // If this day has a direct deletion impact
                $dayDirectDelta = $deltasByDate[$dateStr] ?? 0;
                
                // Total impact on Real to Deliver = Direct Delta + Accumulated Initial Cash Delta
                // Note: The direct delta affects Total Collected. The running cash delta affects Initial Cash.
                
                $currentValues = [
                    'id' => $liquidation->id,
                    'date' => $liquidation->date,
                    'total_collected' => $liquidation->total_collected,
                    'real_to_deliver' => $liquidation->real_to_deliver,
                    'shortage' => $liquidation->shortage,
                    'surplus' => $liquidation->surplus,
                    'initial_cash' => $liquidation->initial_cash
                ];

                // New Initial Cash (affected by previous days)
                $newInitialCash = $liquidation->initial_cash + $runningCashDelta;
                
                // New Total Collected (affected by direct deletion on this day)
                $newTotalCollected = $liquidation->total_collected + $dayDirectDelta;
                
                // New Real To Deliver
                // Formula: Initial + Total Collected - Delivered + (Other metrics assumed constant)
                // Since only Initial and Total Collected change:
                // NewReal = OldReal + (NewInitial - OldInitial) + (NewCollected - OldCollected)
                $newRealToDeliver = $liquidation->real_to_deliver + ($newInitialCash - $liquidation->initial_cash) + ($newTotalCollected - $liquidation->total_collected);
                
                // Recalculate Shortage/Surplus
                // Logic: Cash Delivered vs New Real To Deliver
                $newShortage = 0;
                $newSurplus = 0;
                $diff = $liquidation->cash_delivered - $newRealToDeliver;
                if ($diff < 0) {
                    $newShortage = abs($diff);
                } else {
                    $newSurplus = $diff; // Usually surplus if delivered > real
                }

                $newValues = [
                    'total_collected' => $newTotalCollected,
                    'real_to_deliver' => $newRealToDeliver,
                    'initial_cash' => $newInitialCash,
                    'shortage' => $newShortage,
                    'surplus' => $newSurplus
                ];

                // Add checking logic: Only add to report if values actually changed
                if ($dayDirectDelta != 0 || $runningCashDelta != 0) {
                     $simulationData[] = [
                        'liquidation' => $currentValues,
                        'changes' => $newValues,
                        'installment_impact' => abs($dayDirectDelta)
                    ];
                }
                
                // Update running delta for next day
                // The change in Real To Deliver for today becomes the change in Initial Cash for tomorrow
                 $runningCashDelta = ($newRealToDeliver - $liquidation->real_to_deliver);
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
                    $affectedLiqs = Liquidation::where('seller_id', $sellerId)
                        ->where('date', '>=', $earliestDate)
                        ->orderBy('date', 'asc')
                        ->get();

                    foreach ($affectedLiqs as $liq) {
                        $liquidationService->recalculateLiquidation($liq->seller_id, $liq->date->toDateString());
                    }
                    $liquidationService->recalculateNextLiquidations($sellerId, $earliestDate->toDateString());
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
