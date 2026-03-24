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
     * Generate / Download PDF for a specific payroll
     */
    public function downloadPdf($id)
    {
        $payroll = Payroll::with('seller.user')->findOrFail($id);

        try {
            $pdf = Pdf::loadView('pdf.payroll', compact('payroll'));
            
            // To ensure the blade view exists
            return $pdf->download("Nomina_Semanal_".str_replace(' ', '_', $payroll->seller->user->name)."_{$payroll->start_date}.pdf");
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error generando PDF: ' . $e->getMessage()], 500);
        }
    }
}
