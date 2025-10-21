<?php

namespace App\Http\Controllers;

use App\Exports\SellerLiquidationsDetailExport;
use App\Services\ClientService;
use Illuminate\Http\Request;
use App\Services\LiquidationService;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\AccumulatedByCityExport;
use App\Exports\SellersSummaryByCityExport;
use App\Http\Requests\Report\ExportDateRangeRequest;

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
     * @param ExportDateRangeRequest $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function downloadAccumulatedByCityExcel(ExportDateRangeRequest $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $safeStartDate = str_replace(['/', '\\'], '-', $startDate);
        $safeEndDate = str_replace(['/', '\\'], '-', $endDate);
        try {
            $fileName = 'Reporte_Acumulado_Ciudad_' . $safeStartDate . '_' . $safeEndDate . '.xlsx';
            return \Maatwebsite\Excel\Facades\Excel::download(
                new \App\Exports\AccumulatedByCityExport($startDate, $endDate, $this->liquidationService),
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
     * @param ExportDateRangeRequest $request
     * @param int $sellerId
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function downloadSellersSummaryByCityExcel(ExportDateRangeRequest $request, $sellerId)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $safeStartDate = str_replace(['/', '\\'], '-', $startDate);
        $safeEndDate = str_replace(['/', '\\'], '-', $endDate);
        try {
            $fileName = 'resumen_liquidaciones_vendedores_' . $sellerId . '_' . $safeStartDate . '_' . $safeEndDate . '.xlsx';
            return \Maatwebsite\Excel\Facades\Excel::download(
                new \App\Exports\SellersSummaryByCityExport($sellerId, $startDate, $endDate, $this->clientService),
                $fileName
            );
        } catch (\Exception $e) {
            \Log::error('Error al generar el resumen de liquidaciones por vendedores: ' . $e->getMessage());
            throw new \RuntimeException('No se pudo generar el reporte. Por favor, intente nuevamente.');
        }
    }

    /**
     * Descarga un archivo de Excel con el detalle de liquidaciones por vendedor.
     *
     * @param ExportDateRangeRequest $request
     * @param int $sellerId
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function downloadSellerLiquidationsDetailExcel(ExportDateRangeRequest $request, $sellerId)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $fileName = 'liquidaciones_detalladas_vendedor_' . $sellerId . '_' . $startDate . '_a_' . $endDate . '.xlsx';
        try {
            return \Maatwebsite\Excel\Facades\Excel::download(
                new \App\Exports\SellerLiquidationsDetailExport($sellerId, $startDate, $endDate, $this->liquidationService),
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
