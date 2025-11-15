<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use App\Services\ClientService;
use Carbon\Carbon;

class SellersSummaryByCityExport implements FromCollection, WithHeadings, WithTitle, WithStyles, WithEvents
{
    protected $sellerId;
    protected $startDate;
    protected $endDate;
    protected $clientService;
    protected $generatedAt;

    public function __construct($sellerId, $startDate, $endDate, ClientService $clientService)
    {
        $this->sellerId = $sellerId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->clientService = $clientService;
        $this->generatedAt = Carbon::now()->format('Y-m-d H:i:s');
    }

    public function collection()
    {
        $rows = [
            ['', 'CONTROCD'],
            ['', 'Fecha desde: ' . $this->startDate],
            ['', 'Hasta: ' . $this->endDate],
            ['', 'Generación: ' . now()->format('Y-m-d')],
        ];

        $rows[] = [
            'Préstamo',
            'Nombre del Cliente',
            'Capital',
            'Vr. Pago',
            'Saldo Crédito',
            'Saldo Cartera'
        ];

        $portfolio = $this->clientService->getClientPortfolioBySeller(
            $this->sellerId, 
            $this->startDate, 
            $this->endDate
        );

        $totalCollected = $this->clientService->getTotalCollectedBySeller(
            $this->sellerId, 
            $this->startDate, 
            $this->endDate
        );

        $rows = array_merge($rows, $portfolio->map(function ($item) {
            return [
                $item->loan_id,
                $item->client_name,
                $item->capital,
                $item->paid_value,
                $item->credit_balance,
                $item->portfolio_balance,
            ];
        })->toArray());

        // Fila de total con fondo azul
        $rows[] = [
            '', '', '', '', '', 'VALOR TOTAL RECAUDO A FECHA -- : $ ' . number_format($totalCollected, 2)
        ];

        return collect($rows);
    }

    public function headings(): array
    {
        return [
            ['Préstamo',
            'Nombre del Cliente',
            'Capital',
            'Vr. Pago',
            'Saldo Crédito',
            'Saldo Cartera']
        ];
    }

    public function title(): string
    {
        $title = 'Validación Cartera Diaria';
        // Limitar el título a 31 caracteres y reemplazar caracteres no permitidos
        return substr(preg_replace('#[\\/]#', '-', $title), 0, 31);
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Fila de encabezados
            5 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Arial', 'size' => 14],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2B69E8']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            ],
            // Fila fecha de generación y fechas (negras)
            2 => [
                'font' => ['italic' => true, 'color' => ['rgb' => '000000'], 'name' => 'Arial', 'size' => 12],
                'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
            ],
            3 => [
                'font' => ['italic' => true, 'color' => ['rgb' => '000000'], 'name' => 'Arial', 'size' => 12],
                'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
            ],
            4 => [
                'font' => ['italic' => true, 'color' => ['rgb' => '000000'], 'name' => 'Arial', 'size' => 12],
                'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
            ],
            1 => [
                'font' => ['italic' => true, 'color' => ['rgb' => '555555'], 'name' => 'Arial', 'size' => 12],
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

                // Congelar encabezado principal
                $sheet->freezePane('A3');

                // Bordes para todas las celdas
                $sheet->getStyle('A2:' . $highestColumn . $highestRow)
                      ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                // Ajustar altura de filas
                for ($row = 2; $row <= $highestRow; $row++) {
                    $sheet->getRowDimension($row)->setRowHeight(22);
                }

                // Formato numérico para columnas Capital, Vr. Pago, Saldo Crédito, Saldo Cartera
                $sheet->getStyle('C3:F' . ($highestRow - 1))
                      ->getNumberFormat()->setFormatCode('#,##0.00');

                // Fila de total resaltada con azul claro
                $sheet->getStyle('A' . $highestRow . ':' . $highestColumn . $highestRow)
                    ->applyFromArray([
                        'font' => ['bold' => true, 'name' => 'Arial', 'size' => 12],
                        'fill' => [
                            'fillType' => 'solid',
                            'startColor' => ['rgb' => '2B69E8']
                        ],
                        'alignment' => ['horizontal' => 'right', 'vertical' => 'center'],
                    ]);

                $sheet->mergeCells('B2:F2');
                $sheet->mergeCells('B3:F3');
                $sheet->mergeCells('B4:F4');
                $sheet->mergeCells('B5:F5');

                $sheet->getStyle('B2:F5')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => '2B69E8'], 'name' => 'Arial', 'size' => 16],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
                ]);

                foreach (range(2, 5) as $row) {
                    $sheet->getRowDimension($row)->setRowHeight(25);
                }

                $sheet->getStyle('A6:' . $highestColumn . '6')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Arial', 'size' => 14],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2B69E8']],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
                ]);

                foreach (range(1, $highestRow) as $row) {
                    $sheet->getRowDimension($row)->setRowHeight(22);
                }
            }
        ];
    }
}