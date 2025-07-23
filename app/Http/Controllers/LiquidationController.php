<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Liquidation;
use App\Models\Expense;
use App\Models\Credit;
use App\Models\Seller;
use Illuminate\Support\Facades\Log;

class LiquidationController extends Controller
{
    public function calculateLiquidation(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'seller_id' => 'required|exists:sellers,id'
        ]);

        $user = Auth::user();
        $sellerId = $request->seller_id;
        $date = $request->date;

        // Verificar permisos
        if (!$this->checkAuthorization($user, $sellerId)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Obtener datos para la liquidación
        $data = $this->getLiquidationData($sellerId, $date);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function storeLiquidation(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'seller_id' => 'required|exists:sellers,id',
            'cash_delivered' => 'required|numeric|min:0',
            'initial_cash' => 'required|numeric|min:0',
            'base_delivered' => 'required|numeric|min:0',
            'total_collected' => 'required|numeric|min:0',
            'total_expenses' => 'required|numeric|min:0',
            'new_credits' => 'required|numeric|min:0'
        ]);
    
        // Verificar si ya existe liquidación para este día
        $existingLiquidation = Liquidation::where('seller_id', $request->seller_id)
            ->whereDate('date', $request->date)
            ->first();
    
        if ($existingLiquidation) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe una liquidación para este vendedor en la fecha seleccionada'
            ], 422);
        }
    
        // Calcular el valor real a entregar
        $realToDeliver = $request->initial_cash + $request->total_collected 
                        - $request->total_expenses - $request->new_credits;
    
        // Calcular faltante/sobrante
        $shortage = 0;
        $surplus = 0;
        
        if ($request->cash_delivered < $realToDeliver) {
            $shortage = $realToDeliver - $request->cash_delivered;
        } else {
            $surplus = $request->cash_delivered - $realToDeliver;
        }
    
        // Crear liquidación
        $liquidation = Liquidation::create([
            'date' => $request->date,
            'seller_id' => $request->seller_id,
            'collection_target' => $request->collection_target, // Método para obtener meta diaria
            'initial_cash' => $request->initial_cash,
            'base_delivered' => $request->base_delivered,
            'total_collected' => $request->total_collected,
            'total_expenses' => $request->total_expenses,
            'new_credits' => $request->new_credits,
            'real_to_deliver' => $realToDeliver,
            'shortage' => $shortage,
            'surplus' => $surplus,
            'cash_delivered' => $request->cash_delivered,
            'status' => 'pending'
        ]);
    
        return response()->json([
            'success' => true,
            'data' => $liquidation,
            'message' => 'Liquidación guardada correctamente'
        ]);
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

    public function getLiquidationData($sellerId, $date)
    {
        $user = Auth::user();
        
        // 1. Verificar si ya existe liquidación para esta fecha
        $existingLiquidation = Liquidation::where('seller_id', $sellerId)
            ->whereDate('date', $date)
            ->first();
    
        // Si existe liquidación, retornar directamente esos datos
        if ($existingLiquidation) {
            return $this->formatLiquidationResponse($existingLiquidation, true);
        }
    
        // 2. Obtener datos del endpoint dailyPaymentTotals
        $dailyTotals = $this->getDailyTotals($sellerId, $date, $user);
        
        // 3. Obtener última liquidación para saldo inicial
        $lastLiquidation = Liquidation::where('seller_id', $sellerId)
            ->where('date', '<', $date)
            ->orderBy('date', 'desc')
            ->first();
    
        $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;
    
        // 4. Calcular valor real a entregar
        $realToDeliver = $initialCash + $dailyTotals['collected_total'] 
                        - $dailyTotals['total_expenses'] - $dailyTotals['created_credits_value'];
    
        // 5. Estructurar respuesta completa
        return [
            'collection_target' => $dailyTotals['daily_goal'],
            'initial_cash' => $initialCash,
            'base_delivered' => $dailyTotals['base_value'],
            'total_collected' => $dailyTotals['collected_total'],
            'total_expenses' => $dailyTotals['total_expenses'],
            'new_credits' => $dailyTotals['created_credits_value'],
            'real_to_deliver' => $realToDeliver,
            'date' => $date,
            'seller_id' => $sellerId,
            'cash' => $dailyTotals['cash'],
            'transfer' => $dailyTotals['transfer'],
            'expected_total' => $dailyTotals['expected_total'],
            'current_balance' => $dailyTotals['current_balance'],
            'total_clients' => $dailyTotals['total_clients'],
            'existing_liquidation' => null,
            'last_liquidation' => $lastLiquidation ? $this->formatLiquidationDetails($lastLiquidation) : null,
            'is_new' => true
        ];
    }
    
    // Nuevo método para obtener los dailyTotals
    protected function getDailyTotals($sellerId, $date, $user)
    {
        $query = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(
                'payments.payment_method',
                DB::raw('SUM(payments.amount) as total')
            )
            ->whereDate('payments.payment_date', $date)
            ->where('credits.seller_id', $sellerId)
            ->where('payments.status', 'Aprobado')
            ->groupBy('payments.payment_method');
    
        $paymentResults = $query->get();
    
        $totals = [
            'cash' => 0,
            'transfer' => 0,
            'collected_total' => 0,
            'base_value' => 0
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
    
        // Obtener total esperado
        $totals['expected_total'] = (float)DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('installments.due_date', $date)
            ->sum('installments.quota_amount');
    
        // Obtener créditos creados
        $credits = DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereDate('created_at', $date)
            ->selectRaw('COALESCE(SUM(credit_value), 0) as value, COALESCE(SUM(credit_value * (total_interest / 100)), 0) as interest')
            ->first();
    
        $totals['created_credits_value'] = (float)$credits->value;
        $totals['created_credits_interest'] = (float)$credits->interest;
    
        // Obtener gastos
        $totals['total_expenses'] = (float)Expense::where('user_id', $user->id)
            ->whereDate('created_at', $date)
            ->where('status', 'Aprobado')
            ->sum('value');
    
        // Obtener total clientes
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

        Log::info($totals['expected_total']);
    
        return $totals;
    }
    
    // Método para formatear respuesta de liquidación existente
    protected function formatLiquidationResponse($liquidation, $isExisting = false)
    {
        return [
            'collection_target' => $liquidation->collection_target,
            'initial_cash' => $liquidation->initial_cash,
            'base_delivered' => $liquidation->base_delivered,
            'total_collected' => $liquidation->total_collected,
            'total_expenses' => $liquidation->total_expenses,
            'new_credits' => $liquidation->new_credits,
            'real_to_deliver' => $liquidation->real_to_deliver,
            'date' => $liquidation->date,
            'seller_id' => $liquidation->seller_id,
            'existing_liquidation' => $isExisting ? $this->formatLiquidationDetails($liquidation) : null,
            'last_liquidation' => $this->getPreviousLiquidation($liquidation->seller_id, $liquidation->date),
            'is_new' => false // Indicador de que ya existe
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
            'created_at' => $liquidation->created_at
        ];
    }
    public function getLiquidationHistory(Request $request)
    {
        $request->validate([
            'seller_id' => 'required|exists:sellers,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        $user = Auth::user();
        $sellerId = $request->seller_id;


        // Verificar permisos
        if (!$this->checkAuthorization($user, $sellerId)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $history = Liquidation::with(['expenses', 'credits'])
            ->where('seller_id', $sellerId)
            ->whereBetween('date', [$request->start_date, $request->end_date])
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }
}
