<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\Seller;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class PayrollController extends Controller
{
    /**
     * Display a listing of the resource for Superadmin.
     */
    public function index(Request $request)
    {
        // Ideally restricted to role_id 1 and 2
        $query = Payroll::with('seller.user');

        if ($request->has('seller_id') && $request->seller_id) {
            $query->where('seller_id', $request->seller_id);
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('start_date', [$request->start_date, $request->end_date]);
        }

        $payrolls = $query->orderBy('start_date', 'desc')->paginate(15);

        // Add daily breakdown for visualization
        $payrolls->getCollection()->transform(function ($payroll) {
            $dailyData = \App\Models\Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
                ->where('credits.seller_id', $payroll->seller_id)
                ->whereBetween('payments.payment_date', [$payroll->start_date->toDateString(), $payroll->end_date->toDateString()])
                ->where('payments.status', '!=', 'Anulado')
                ->select([
                    \Illuminate\Support\Facades\DB::raw('DAYOFWEEK(payments.payment_date) as day'),
                    \Illuminate\Support\Facades\DB::raw('SUM(payments.amount) as total_amount'),
                    \Illuminate\Support\Facades\DB::raw('SUM(payments.amount * (credits.total_interest / (100 + credits.total_interest))) as total_utility')
                ])
                ->groupBy('day')
                ->get()
                ->keyBy('day');

            // Calculate the effective commission rates for this payroll
            $collRate = $payroll->total_collected > 0 ? ($payroll->commission_collection / $payroll->total_collected) : 0;
            $utilRate = $payroll->total_utility > 0 ? ($payroll->commission_utility / $payroll->total_utility) : 0;

            // Map MySQL DAYOFWEEK (1=Sun, 2=Mon...7=Sat) to Mon-Sat (1=Mon...6=Sat)
            $breakdown = [];
            for ($i = 2; $i <= 7; $i++) {
                $day = $dailyData->get($i);
                $amt = (float)($day->total_amount ?? 0);
                $utl = (float)($day->total_utility ?? 0);
                
                // The "Ganancia" of the day is the commission earned by the seller (Recaudo * % OR Utility * %)
                $dailyGanancia = ($amt * $collRate) + ($utl * $utilRate);

                $breakdown[] = [
                    'amount' => $amt,
                    'utility' => $dailyGanancia
                ];
            }
            $payroll->daily_breakdown = $breakdown;
            return $payroll;
        });

        return response()->json([
            'success' => true,
            'message' => 'Nóminas listadas',
            'data' => $payrolls
        ]);
    }

    /**
     * Display a listing for a specific seller
     */
    public function myPayrolls(Request $request)
    {
        $seller = Seller::where('user_id', auth()->id())->first();

        if (!$seller) {
            return response()->json(['success' => false, 'message' => 'No seller profile found'], 404);
        }

        $payrolls = Payroll::where('seller_id', $seller->id)
            ->orderBy('id', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $payrolls
        ]);
    }

    /**
     * Mark payroll as paid
     */
    public function markAsPaid($id)
    {
        $payroll = Payroll::findOrFail($id);
        $payroll->status = 'paid';
        $payroll->save();

        return response()->json([
            'success' => true,
            'message' => 'Nómina marcada como pagada',
            'data' => $payroll
        ]);
    }

    /**
     * Update the specified payroll in storage.
     */
    public function update(Request $request, $id)
    {
        $payroll = Payroll::findOrFail($id);

        $data = $request->validate([
            'salary' => 'nullable|numeric',
            'commission_utility' => 'nullable|numeric',
            'commission_collection' => 'nullable|numeric',
            'commission_credits' => 'nullable|numeric',
            'allowance' => 'nullable|numeric',
            'deductions_savings' => 'nullable|numeric',
            'deductions_arl' => 'nullable|numeric',
            'status' => 'nullable|string',
        ]);

        $payroll->fill($data);

        // Recalculate net_total
        $payroll->net_total = (float)$payroll->salary + 
                            (float)$payroll->commission_utility + 
                            (float)$payroll->commission_collection + 
                            (float)$payroll->commission_credits + 
                            (float)$payroll->allowance - 
                            ((float)$payroll->deductions_savings + (float)$payroll->deductions_arl);
        
        $payroll->net_total = max(0, $payroll->net_total);
        $payroll->save();

        return response()->json([
            'success' => true,
            'message' => 'Nómina actualizada exitosamente',
            'data' => $payroll->load('seller.user')
        ]);
    }

    /**
     * Get detailed collection breakdown for a specific payroll
     */
    public function details($id)
    {
        $payroll = Payroll::findOrFail($id);

        // Fetch Applied Payments
        $applied = \App\Models\Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->where('credits.seller_id', $payroll->seller_id)
            ->whereBetween('payments.payment_date', [$payroll->start_date->toDateString(), $payroll->end_date->toDateString()])
            ->where('payments.status', '!=', 'Anulado')
            ->select([
                'payments.id',
                'clients.name as client_name',
                'credits.total_amount as credit_total',
                'credits.credit_value as credit_value',
                'credits.total_interest as credit_interest',
                'credits.remaining_amount as credit_pending',
                'credits.status as credit_status',
                'payments.amount as payment_amount',
                'payments.payment_date as payment_date'
            ])
            ->orderBy('payments.payment_date', 'asc')
            ->get();

        // Fetch Deleted/Anulados Payments (using onlyTrashed)
        $deleted = \App\Models\Payment::onlyTrashed()
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->where('credits.seller_id', $payroll->seller_id)
            ->whereBetween('payments.payment_date', [$payroll->start_date->toDateString(), $payroll->end_date->toDateString()])
            ->select([
                'payments.id',
                'clients.name as client_name',
                'payments.amount as payment_amount',
                'payments.payment_date as payment_date',
                'payments.deleted_at'
            ])
            ->get();

        // Transform results
        $transformer = function ($item) {
            $actualTotal = (float)$item->credit_total;
            if ($actualTotal <= 0 && isset($item->credit_value)) {
                $actualTotal = (float)$item->credit_value * (1 + ((float)$item->credit_interest / 100));
            }
            $item->credit_total = $actualTotal;
            if (isset($item->credit_pending)) {
                $item->credit_paid = (float)$actualTotal - (float)$item->credit_pending;
            }
            return $item;
        };

        $applied->transform($transformer);

        return response()->json([
            'success' => true,
            'data' => [
                'applied' => $applied,
                'deleted' => $deleted
            ]
        ]);
    }

    /**
     * Generate / Download PDF for a specific payroll
     */
    public function downloadPdf($id)
    {
        $payroll = Payroll::with(['seller.user', 'seller.company'])->findOrFail($id);

        try {
            $pdf = Pdf::loadView('pdf.payroll', compact('payroll'));
            
            // To ensure the blade view exists
            return $pdf->download("Nomina_Semanal_".str_replace(' ', '_', $payroll->seller->user->name)."_{$payroll->start_date}.pdf");
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error generando PDF: ' . $e->getMessage()], 500);
        }
    }
}
