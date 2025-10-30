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
            [ '','Fecha desde: ' . $this->startDate],
            ['', 'Hasta: ' . $this->endDate],
            ['', 'Generación: ' . now()->format('Y-m-d')],
            ['', ''],

      
        ];

        $logoPath = 'public/images/favicon.svg'; // Path to the logo
        if (file_exists(public_path($logoPath))) {
            $rows[5][1] = asset($logoPath);
        }

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
                    $sheet->getColumnDimension($col)->setWidth(25);
                }

                $highestRow = $sheet->getHighestRow(); 

                $sheet->getStyle('B1')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('2B69E8'); 
                $sheet->getStyle('B2:B4')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('2B69E8'); 

                $sheet->getStyle('A6:' . $sheet->getHighestColumn() . '6')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('2B69E8'); 

                $totalCiudadRow = $highestRow - 3;
                $sheet->getStyle("A$totalCiudadRow:" . $sheet->getHighestColumn() . "$totalCiudadRow")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('2B69E8');

                $totalGlobalRow = $highestRow - 1;
                $sheet->getStyle("A$totalGlobalRow:" . $sheet->getHighestColumn() . "$totalGlobalRow")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('2B69E8'); 

                $sheet->getStyle('B1:B4')->getFont()->setBold(true)->setSize(16);
                $sheet->getStyle('A6:' . $sheet->getHighestColumn() . '6')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle("A$totalCiudadRow:" . $sheet->getHighestColumn() . "$totalCiudadRow")->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle("A$totalGlobalRow:" . $sheet->getHighestColumn() . "$totalGlobalRow")->getFont()->setBold(true)->setSize(14);

                $sheet->getStyle('B1:B4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('B1:B4')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

                $sheet->getStyle('A6:' . $sheet->getHighestColumn() . '6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('A6:' . $sheet->getHighestColumn() . '6')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

                foreach (range('A', $sheet->getHighestColumn()) as $col) {
                    $sheet->getColumnDimension($col)->setWidth(30);
                }

                foreach (range(1, $highestRow) as $row) {
                    $sheet->getRowDimension($row)->setRowHeight(25);
                }

                $highestRow = $sheet->getHighestRow();
                $sheet->getStyle("A1:" . $sheet->getHighestColumn() . "$highestRow")->getBorders()->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                $sheet->getStyle("A$totalCiudadRow:" . $sheet->getHighestColumn() . "$totalCiudadRow")->getFont()->setBold(true);

                $sheet->getStyle("A$totalGlobalRow:" . $sheet->getHighestColumn() . "$totalGlobalRow")->getFont()->setBold(true)->setSize(14);

                foreach ([$totalCiudadRow-2, $totalCiudadRow-1, $totalGlobalRow-2, $totalGlobalRow-1, $highestRow] as $row) {
                    $sheet->getRowDimension($row)->setRowHeight(20);
                }

                $logoPath = public_path('images/favicon.svg'); 
                if (file_exists($logoPath)) {
                    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                    $drawing->setName('Logo');
                    $drawing->setDescription('Logo');
                    $drawing->setPath($logoPath); 
                    $drawing->setHeight(80); 
                    $drawing->setCoordinates('B1'); 
                    $drawing->setOffsetX(50); 
                    $drawing->setOffsetY(10); 
                    $drawing->setWorksheet($sheet->getDelegate());
                } else {
                    $sheet->setCellValue('B1', 'LOGO NO DISPONIBLE');
                    $sheet->getStyle('B1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                }

                $sheet->getStyle('B1:B4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('B1:B4')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

                $sheet->getStyle('A6:' . $sheet->getHighestColumn() . '6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('A6:' . $sheet->getHighestColumn() . '6')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

                foreach (range('A', $sheet->getHighestColumn()) as $col) {
                    $sheet->getColumnDimension($col)->setWidth(30);
                }

                foreach (range(1, $highestRow) as $row) {
                    $sheet->getRowDimension($row)->setRowHeight(25);
                }
            }
        ];
    }
}