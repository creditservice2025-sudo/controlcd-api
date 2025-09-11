<?php

namespace App\Http\Controllers;

use App\Exports\SellerLiquidationsDetailExport;
use App\Services\ClientService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\LiquidationService;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\AccumulatedByCityExport;
use App\Exports\SellersSummaryByCityExport;


class ReportExportController extends Controller
{
    /**
     * @var LiquidationService
     */
    protected $liquidationService;


    protected $clientService;


    /**
     * Constructor del controlador.
     *
     * @param LiquidationService $liquidationService
     * @param ClientService $clientService
     */
    public function __construct(LiquidationService $liquidationService, ClientService $clientService)
    {
        $this->liquidationService = $liquidationService;
        $this->clientService = $clientService;
    }


    /**
     * Descarga un archivo de Excel con el resumen acumulado por ciudad.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function downloadAccumulatedByCityExcel(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException('Validación fallida: ' . $validator->errors()->first());
        }

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        try {
            $fileName = 'Reporte_Acumulado_Ciudad_' . $startDate . '_' . $endDate . '.xlsx';


            return Excel::download(
                new AccumulatedByCityExport($startDate, $endDate, $this->liquidationService),
                $fileName
            );
        } catch (\Exception $e) {
            \Log::error('Error al exportar el reporte: ' . $e->getMessage());
            throw new \RuntimeException('No se pudo generar el reporte. Por favor, intente nuevamente.');
        }
    }



    /**
     * Descarga un archivo de Excel con el resumen de liquidaciones por vendedor en una ciudad.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function downloadSellersSummaryByCityExcel(Request $request, $sellerId)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException('Validación fallida: ' . $validator->errors()->first());
        }

        try {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            return Excel::download(
                new SellersSummaryByCityExport($sellerId, $startDate, $endDate, $this->clientService),
                'resumen_liquidaciones_vendedores_' . $sellerId . '_' . $startDate . '_' . $endDate . '.xlsx'
            );
        } catch (\Exception $e) {
            \Log::error('Error al generar el resumen de liquidaciones por vendedores: ' . $e->getMessage());
            throw new \RuntimeException('No se pudo generar el reporte. Por favor, intente nuevamente.');
        }
    }
    public function downloadSellerLiquidationsDetailExcel(Request $request, $sellerId)
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



            $fileName = 'liquidaciones_detalladas_vendedor_' . $sellerId . '_' . $startDate . '_a_' . $endDate . '.xlsx';

            return Excel::download(
                new SellerLiquidationsDetailExport($sellerId, $startDate, $endDate, $this->liquidationService),
                $fileName
            );
        } catch (\Exception $e) {
            \Log::error('Error al exportar el reporte de liquidaciones detalladas: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
