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

        $rows = $portfolio->map(function ($item) {
            return [
                $item->loan_id,
                $item->client_name,
                $item->capital,
                $item->paid_value,
                $item->credit_balance,
                $item->portfolio_balance,
            ];
        })->toArray();

        // Fila de total como la imagen
        $rows[] = [
            '', '', '', '', '', 'VALOR TOTAL RECAUDO A FECHA -- : $ ' . number_format($totalCollected, 2)
        ];

        return collect($rows);
    }

    public function headings(): array
    {
        return [
            ['Reporte generado el:', $this->generatedAt], // Fila extra arriba del encabezado
            [
                'Préstamo',
                'Nombre del Cliente',
                'Capital',
                'Vr. Pago',
                'Saldo Crédito',
                'Saldo Cartera',
            ]
        ];
    }

    public function title(): string
    {
        return 'Validación Cartera Diaria';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Fila de encabezados
            2 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2980B9']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            ],
            // Fila fecha de generación
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

                // Fila de total resaltada
                $sheet->getStyle('A' . $highestRow . ':' . $highestColumn . $highestRow)
                    ->applyFromArray([
                        'font' => ['bold' => true],
                        'fill' => [
                            'fillType' => 'solid',
                            'startColor' => ['rgb' => 'FFEB3B']
                        ],
                        'alignment' => ['horizontal' => 'right', 'vertical' => 'center'],
                    ]);
            }
        ];
    }
}