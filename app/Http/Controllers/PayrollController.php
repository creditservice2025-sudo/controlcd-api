<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\Seller;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class PayrollController extends Controller
{
    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    /**
     * Display a listing of the resource for Superadmin.
     */
    public function index(Request $request)
    {
        // Ideally restricted to role_id 1 and 2
        $query = Payroll::with(['seller.user', 'seller.config', 'updatedBy']);

        if ($request->has('seller_id') && $request->seller_id) {
            $query->where('seller_id', $request->seller_id);
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }
        
        $hasDateFilter = $request->has('start_date') && $request->has('end_date');
        if ($hasDateFilter) {
            $query->whereBetween('start_date', [$request->start_date, $request->end_date]);
        }

        if ($request->has('frequency') && $request->frequency) {
            $query->where('payroll_frequency', $request->frequency);
        }

        $payrolls = $query->orderBy('start_date', 'desc')->paginate(30); // increased for total visibility
        
        $results = $payrolls->getCollection();

        // Proactive DevOps Logic: 
        // If we are on Page 1 AND no specific date range is filtered, 
        // ensure EVERY active seller has a row (current period or unconfigured).
        if ($payrolls->currentPage() == 1 && !$hasDateFilter) {
            $activeSellers = \App\Models\Seller::where('active', true)->with('user', 'config')->get();
            
            foreach ($activeSellers as $seller) {
                // If this seller is NOT in the current collection for the current period range
                $isParameterized = $this->payrollService->isParameterized($seller);
                
                if ($isParameterized) {
                    $currentPayroll = $this->payrollService->getOrCreateCurrentPayroll($seller);
                    if ($currentPayroll) {
                        $exists = $results->contains(function ($p) use ($currentPayroll) {
                            return $p->seller_id == $currentPayroll->seller_id && 
                                   $p->start_date == $currentPayroll->start_date && 
                                   $p->end_date == $currentPayroll->end_date;
                        });
                        
                        if (!$exists) {
                            $results->push($currentPayroll);
                        }
                    }
                } else {
                    // Unconfigured seller logic (existing)
                    if (!$results->pluck('seller_id')->contains($seller->id)) {
                        $uPayroll = new Payroll([
                            'seller_id' => $seller->id,
                            'is_parameterized' => false,
                            'status' => 'pending',
                            'net_total' => 0
                        ]);
                        $uPayroll->setRelation('seller', $seller);
                        $results->push($uPayroll);
                    }
                }
            }
        }

        // Alphabetical Sorting by Seller Name
        $sortedResults = $results->sortBy(function($p) {
            return $p->seller->user->name ?? 'Z';
        })->values();

        // Replace the collection in the paginator
        $payrolls->setCollection($sortedResults);

        // Add daily breakdown and parameterized flag
        $sortedResults->transform(function ($payroll) {
            if (!$payroll->seller) {
                $payroll->is_parameterized = false;
                $payroll->daily_breakdown = [];
                return $payroll;
            }

            $payroll->is_parameterized = $payroll->is_parameterized ?? $this->payrollService->isParameterized($payroll->seller);
            
            if (!$payroll->is_parameterized || !$payroll->start_date) {
                $payroll->daily_breakdown = [];
                return $payroll;
            }

            $dailyData = \App\Models\Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
                ->where('credits.seller_id', $payroll->seller_id)
                ->whereBetween('payments.payment_date', [
                    Carbon::parse($payroll->start_date)->toDateString(), 
                    Carbon::parse($payroll->end_date)->toDateString()
                ])
                ->where('payments.status', '!=', 'Anulado')
                ->select([
                    'payments.payment_date',
                    \Illuminate\Support\Facades\DB::raw('SUM(payments.amount) as total_amount'),
                    \Illuminate\Support\Facades\DB::raw('SUM(payments.amount * (credits.total_interest / (100 + credits.total_interest))) as total_utility')
                ])
                ->groupBy('payments.payment_date')
                ->get()
                ->keyBy('payment_date');

            // Calculate the effective commission rates for this payroll
            $collRate = $payroll->total_collected > 0 ? ($payroll->commission_collection / $payroll->total_collected) : 0;
            $utilRate = $payroll->total_utility > 0 ? ($payroll->commission_utility / $payroll->total_utility) : 0;

            // Generate breakdown for every day in the period (dynamic)
            $breakdown = [];
            $start = Carbon::parse($payroll->start_date);
            $end = Carbon::parse($payroll->end_date);
            
            // Limit to avoid infinite loops if dates are messy
            $maxDays = 31; 
            $count = 0;

            for ($date = $start->copy(); $date->lte($end) && $count < $maxDays; $date->addDay()) {
                $dateStr = $date->toDateString();
                $dayData = $dailyData->get($dateStr);
                
                $amt = (float)($dayData->total_amount ?? 0);
                $utl = (float)($dayData->total_utility ?? 0);
                
                // The "Ganancia" of the day
                $dailyGanancia = ($amt * $collRate) + ($utl * $utilRate);

                // Day name in Spanish
                $dayLabels = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
                $label = $dayLabels[$date->dayOfWeek];

                $breakdown[] = [
                    'date' => $dateStr,
                    'label' => $label,
                    'amount' => $amt,
                    'utility' => $dailyGanancia
                ];
                $count++;
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

        $payrolls = Payroll::with(['seller.user', 'seller.config', 'updatedBy'])
            ->where('seller_id', $seller->id)
            ->orderBy('id', 'desc')
            ->paginate(15);

        // Add daily breakdown for visualization
        $payrolls->getCollection()->transform(function ($payroll) {
            $dailyData = \App\Models\Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
                ->where('credits.seller_id', $payroll->seller_id)
                ->whereBetween('payments.payment_date', [
                    Carbon::parse($payroll->start_date)->toDateString(), 
                    Carbon::parse($payroll->end_date)->toDateString()
                ])
                ->where('payments.status', '!=', 'Anulado')
                ->select([
                    'payments.payment_date',
                    \Illuminate\Support\Facades\DB::raw('SUM(payments.amount) as total_amount'),
                    \Illuminate\Support\Facades\DB::raw('SUM(payments.amount * (credits.total_interest / (100 + credits.total_interest))) as total_utility')
                ])
                ->groupBy('payments.payment_date')
                ->get()
                ->keyBy('payment_date');

            $collRate = $payroll->total_collected > 0 ? ($payroll->commission_collection / $payroll->total_collected) : 0;
            $utilRate = $payroll->total_utility > 0 ? ($payroll->commission_utility / $payroll->total_utility) : 0;

            $breakdown = [];
            $start = Carbon::parse($payroll->start_date);
            $end = Carbon::parse($payroll->end_date);
            
            $count = 0;
            for ($date = $start->copy(); $date->lte($end) && $count < 31; $date->addDay()) {
                $dateStr = $date->toDateString();
                $dayData = $dailyData->get($dateStr);
                
                $amt = (float)($dayData->total_amount ?? 0);
                $utl = (float)($dayData->total_utility ?? 0);
                $dailyGanancia = ($amt * $collRate) + ($utl * $utilRate);

                $dayLabels = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
                $label = $dayLabels[$date->dayOfWeek];

                $breakdown[] = [
                    'date' => $dateStr,
                    'label' => $label,
                    'amount' => $amt,
                    'utility' => $dailyGanancia
                ];
                $count++;
            }
            $payroll->daily_breakdown = $breakdown;
            return $payroll;
        });

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
            // Seller config fields
            'payroll_frequency' => 'nullable|string',
            'payroll_start_day' => 'nullable|integer',
            'include_sundays' => 'nullable|boolean',
        ]);

        $payroll->fill($data);
        $payroll->updated_by_id = auth()->id();

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
            'data' => $payroll->load('seller.user', 'seller.config', 'updatedBy')
        ]);
    }

    /**
     * Recalculate payroll based on current seller config.
     */
    public function recalculate(Request $request, $id)
    {
        $payroll = Payroll::findOrFail($id);
        $seller = $payroll->seller;

        if (!$seller) {
            return response()->json(['success' => false, 'message' => 'Vendedor no encontrado'], 404);
        }

        // Update config overrides if provided in the recalulate request
        // Save them on the payroll itself as requested: "las parametrización se ajustan solo en la nomina seleccionada"
        $payrollData = [];
        if ($request->has('payroll_frequency')) $payrollData['payroll_frequency'] = $request->payroll_frequency;
        if ($request->has('payroll_start_day')) $payrollData['payroll_start_day'] = $request->payroll_start_day;
        if ($request->has('include_sundays')) $payrollData['include_sundays'] = $request->include_sundays;

        if (!empty($payrollData)) {
            $payrollData['updated_by_id'] = auth()->id();
            $payroll->update($payrollData);
        }

        // Check if frequency changed and we need to resize the period
        if ($request->has('payroll_frequency') && $request->payroll_frequency !== $payroll->payroll_frequency) {
            $newDates = $this->payrollService->calculatePeriod(
                $seller, 
                Carbon::parse($payroll->start_date), 
                $request->payroll_frequency
            );
            
            if ($newDates) {
                $payroll->start_date = $newDates['start'];
                $payroll->end_date = $newDates['end'];
                $payroll->payroll_frequency = $request->payroll_frequency;
                $payroll->save();
            }
        }

        // Use our service to generate/update the payroll
        // Note: The service uses the seller's current config. 
        // We override the seller's temporary config object for this specific generation.
        if ($payroll->payroll_frequency) $seller->config->payroll_frequency = $payroll->payroll_frequency;
        if ($payroll->payroll_start_day) $seller->config->payroll_start_day = $payroll->payroll_start_day;
        if ($request->has('include_sundays')) $seller->config->include_sundays = $payroll->include_sundays;

        $updatedPayroll = $this->payrollService->generateForSeller(
            $seller, 
            Carbon::parse($payroll->start_date), 
            Carbon::parse($payroll->end_date)
        );

        if (!$updatedPayroll) {
            return response()->json(['success' => false, 'message' => 'No se pudo recalcular la nómina'], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Nómina recalculada con éxito',
            'data' => $updatedPayroll->load('seller.user', 'seller.config', 'updatedBy')
        ]);
    }

    /**
     * Get all processed date ranges for calendar highlighting
     */
    public function getProcessedDates(Request $request)
    {
        $query = Payroll::query();

        if ($request->has('seller_id') && $request->seller_id) {
            $query->where('seller_id', $request->seller_id);
        }

        // If it's a seller, only their dates
        if (auth()->user()->role_id == 3) {
            $seller = Seller::where('user_id', auth()->id())->first();
            if ($seller) {
                $query->where('seller_id', $seller->id);
            }
        }

        $dates = $query->select('start_date', 'end_date')
            ->get()
            ->map(function ($p) {
                return [
                    'from' => Carbon::parse($p->start_date)->toDateString(),
                    'to' => Carbon::parse($p->end_date)->toDateString()
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $dates
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
            ->whereBetween('payments.payment_date', [
                \Carbon\Carbon::parse($payroll->start_date)->toDateString(), 
                \Carbon\Carbon::parse($payroll->end_date)->toDateString()
            ])
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
            ->whereBetween('payments.payment_date', [
                \Carbon\Carbon::parse($payroll->start_date)->toDateString(), 
                \Carbon\Carbon::parse($payroll->end_date)->toDateString()
            ])
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
