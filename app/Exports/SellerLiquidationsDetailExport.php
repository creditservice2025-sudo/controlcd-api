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

        return collect($results);
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
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2563EB']], // Azul claro
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

                foreach (range('A', $highestColumn) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $sheet->freezePane('A3');

                $sheet->getStyle('A2:' . $highestColumn . $highestRow)
                      ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                for ($row = 2; $row <= $highestRow; $row++) {
                    $sheet->getRowDimension($row)->setRowHeight(22);
                }

                $sheet->getStyle('C3:M' . $highestRow)
                      ->getNumberFormat()
                      ->setFormatCode('#,##0.00');

                $sheet->mergeCells('A1:C1');

                $sheet->getStyle('A2:' . $highestColumn . '2')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2563EB']],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
                ]);

                // Agregar fila de totales al final
                $totalRow = $highestRow + 1;
                $sheet->setCellValue('A' . $totalRow, 'TOTALES');
                $sheet->setCellValue('B' . $totalRow, '');
                
                // Fórmulas de suma para cada columna numérica
                $sheet->setCellValue('C' . $totalRow, '=SUM(C3:C' . $highestRow . ')');
                $sheet->setCellValue('D' . $totalRow, '=SUM(D3:D' . $highestRow . ')');
                $sheet->setCellValue('E' . $totalRow, '=SUM(E3:E' . $highestRow . ')');
                $sheet->setCellValue('F' . $totalRow, '=SUM(F3:F' . $highestRow . ')');
                $sheet->setCellValue('G' . $totalRow, '=SUM(G3:G' . $highestRow . ')');
                $sheet->setCellValue('H' . $totalRow, '=SUM(H3:H' . $highestRow . ')');
                $sheet->setCellValue('I' . $totalRow, '=SUM(I3:I' . $highestRow . ')');
                $sheet->setCellValue('J' . $totalRow, '=SUM(J3:J' . $highestRow . ')');
                $sheet->setCellValue('K' . $totalRow, '=SUM(K3:K' . $highestRow . ')');
                $sheet->setCellValue('L' . $totalRow, '=SUM(L3:L' . $highestRow . ')');

                // Estilo para la fila de totales
                $sheet->getStyle('A' . $totalRow . ':' . $highestColumn . $totalRow)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E40AF']],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
                ]);

                $sheet->getStyle('C' . $totalRow . ':L' . $totalRow)
                      ->getNumberFormat()
                      ->setFormatCode('#,##0.00');

                $sheet->getRowDimension($totalRow)->setRowHeight(25);

                // Bordes para la fila de totales
                $sheet->getStyle('A' . $totalRow . ':' . $highestColumn . $totalRow)
                      ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            }
        ];
    }
}