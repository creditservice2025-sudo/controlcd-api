<?php
namespace App\Exports;

use App\Services\LiquidationService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class AccumulatedByCityExport implements FromArray, WithEvents
{
    protected $startDate;
    protected $endDate;
    protected $service;

    public function __construct($startDate, $endDate, LiquidationService $service)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->service = $service;
    }

    public function array(): array
    {
        $report = $this->service->getReportByCity($this->startDate, $this->endDate);

        // Encabezados superiores
        $rows = [
            ['', 'CONTROCD'],
            ['', 'FECHA DESDE: ' . $this->startDate],
            ['', 'HASTA: ' . $this->endDate],
            ['', 'Generación: ' . now()->format('Y-m-d')],
            [''],
        ];

        // Encabezados dinámicos por ciudad
        $headerRow = [];
        $subHeaderRow = [];
        foreach ($report as $ciudad) {
            $headerRow[] = $ciudad['city'];
            $headerRow[] = 'CIFRAS';
            $subHeaderRow[] = '';
            $subHeaderRow[] = '';
        }
        $rows[] = $headerRow;
        $rows[] = $subHeaderRow;

        $conceptos = [
            'CAJA ANTERIOR' => 'previous_cash',
            'COBRO' => 'collected',
            'PRESTAMOS' => 'loans',
            'INGRESOS' => 'ingresos',
            'GASTOS' => 'expenses',
            'CAJA ACTUAL' => 'current_cash',
        ];

        foreach ($conceptos as $concepto => $key) {
            $fila = [];
            foreach ($report as $ciudad) {
                if (is_array($key)) {
                    $fila[] = $concepto;
                    $fila[] = $ciudad[$key[0]][$key[1]] ?? 0;
                } else {
                    $fila[] = $concepto;
                    $fila[] = $ciudad[$key] ?? 0;
                }
            }
            $rows[] = $fila;
        }

        $rows[] = ['', ''];
        $rows[] = ['', ''];

        $filaTotal = [];
        foreach ($report as $ciudad) {
            $filaTotal[] = 'TOTAL CAJA GENERAL';
            $filaTotal[] = $ciudad['current_cash'] ?? 0;
        }
        $rows[] = $filaTotal;

        $rows[] = ['', ''];
        $rows[] = ['', ''];

        $total_global = array_sum(array_column($report, 'current_cash'));
        $colspan = count($report) * 2; 
        $totalGlobalRow = array_fill(0, $colspan, '');
        $totalGlobalRow[0] = 'TOTAL GLOBAL:';
        $totalGlobalRow[1] = $total_global;
        $rows[] = $totalGlobalRow;

        // Espacio final
        $rows[] = ['', ''];

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet;

                foreach (range('A', $sheet->getHighestColumn()) as $col) {
                    $sheet->getColumnDimension($col)->setWidth(17);
                }

                $sheet->getStyle('B1:B4')->getFont()->setBold(true)->setSize(13);
                $sheet->getStyle('B1')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFC5E0B4');
                $sheet->getStyle('B2:B4')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFE2EFDA');

                $sheet->getStyle('A6:' . $sheet->getHighestColumn() . '6')->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle('A6:' . $sheet->getHighestColumn() . '6')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFEB9C');
                $highestRow = $sheet->getHighestRow();
                $sheet->getStyle("A1:" . $sheet->getHighestColumn() . "$highestRow")->getBorders()->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                $totalCiudadRow = $highestRow - 3; 
                $sheet->getStyle("A$totalCiudadRow:" . $sheet->getHighestColumn() . "$totalCiudadRow")->getFont()->setBold(true);
                $sheet->getStyle("A$totalCiudadRow:" . $sheet->getHighestColumn() . "$totalCiudadRow")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFB6D7A8');

                $totalGlobalRow = $highestRow - 1;
                $sheet->getStyle("A$totalGlobalRow:" . $sheet->getHighestColumn() . "$totalGlobalRow")->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle("A$totalGlobalRow:" . $sheet->getHighestColumn() . "$totalGlobalRow")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FF0050EF');

                foreach ([$totalCiudadRow-2, $totalCiudadRow-1, $totalGlobalRow-2, $totalGlobalRow-1, $highestRow] as $row) {
                    $sheet->getRowDimension($row)->setRowHeight(18);
                }
            }
        ];
    }
}