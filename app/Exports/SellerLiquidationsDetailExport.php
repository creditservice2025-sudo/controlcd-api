<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use App\Services\LiquidationService;
use Carbon\Carbon;

class SellerLiquidationsDetailExport implements FromCollection, WithHeadings, WithTitle, WithMapping, WithStyles, WithEvents
{
    protected $sellerId;
    protected $startDate;
    protected $endDate;
    protected $liquidationService;
    protected $sellerName;
    protected $generatedAt;

    public function __construct($sellerId, $startDate, $endDate, LiquidationService $liquidationService, $sellerName = '')
    {
        $this->sellerId = $sellerId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->liquidationService = $liquidationService;
        $this->sellerName = $sellerName;
        $this->generatedAt = Carbon::now()->format('Y-m-d H:i:s');
    }

    public function collection()
    {
        $results = $this->liquidationService->getSellerLiquidationsDetail(
            $this->sellerId, 
            $this->startDate, 
            $this->endDate
        );

        // return collect($results); // Descomenta esto si ya tienes $results como array/collection
        return collect([]);
    }

    public function map($liquidation): array
    {
        return [
            $liquidation->date ? Carbon::parse($liquidation->date)->format('d/m/Y') : '',
            $liquidation->seller->user->name ?? 'N/A',
            number_format($liquidation->total_collected, 2),
            number_format($liquidation->total_expenses, 2),
            number_format($liquidation->total_income, 2),
            number_format($liquidation->new_credits, 2),
            number_format($liquidation->initial_cash, 2),
            number_format($liquidation->base_delivered, 2),
            number_format($liquidation->real_to_deliver, 2),
            number_format($liquidation->shortage, 2),
            number_format($liquidation->surplus, 2),
            number_format($liquidation->cash_delivered, 2),
            $liquidation->created_at ? Carbon::parse($liquidation->created_at)->format('d/m/Y H:i') : '',
        ];
    }

    public function headings(): array
    {
        return [
            ['Reporte generado el:', $this->generatedAt], // Fila extra arriba del encabezado
            [
                'Fecha',
                'Nombre del Vendedor',
                'Total Recaudado',
                'Total Gastos',
                'Total Ingresos',
                'Nuevos Créditos',
                'Efectivo Inicial',
                'Base Entregada',
                'Real a Entregar',
                'Faltante',
                'Sobrante',
                'Efectivo Entregado',
                'Fecha de Creación'
            ]
        ];
    }

    public function title(): string
    {
        $title = 'Liquidaciones Detalladas';
        if (!empty($this->sellerName)) {
            $title .= ' - ' . $this->sellerName;
        }
        return substr($title, 0, 31);
    }

    public function styles(Worksheet $sheet)
    {
        return [
            2 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2980B9']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            ],
            1 => [
                'font' => ['italic' => true, 'color' => ['rgb' => '555555']],
                'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestColumn = $sheet->getHighestColumn();
                $highestRow = $sheet->getHighestRow();

                // Auto ancho de columnas
                foreach (range('A', $highestColumn) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                // Congelar la primera fila (encabezado real)
                $sheet->freezePane('A3');

                // Bordes para todas las celdas de datos
                $sheet->getStyle('A2:' . $highestColumn . $highestRow)
                      ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                // Ajustar altura de las filas
                for ($row = 2; $row <= $highestRow; $row++) {
                    $sheet->getRowDimension($row)->setRowHeight(22);
                }

                // Formatear números
                $sheet->getStyle('C3:M' . $highestRow)
                      ->getNumberFormat()
                      ->setFormatCode('#,##0.00');

                // Centrar encabezado principal
                $sheet->mergeCells('A1:C1');
            }
        ];
    }
}