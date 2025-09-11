<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class GenericExport implements FromArray, WithHeadings, WithEvents
{
    protected $headings;
    protected $data;
    protected $totals;

    public function __construct($headings, $data, $totals = [])
    {
        $this->headings = $headings;
        $this->data = $data;
        $this->totals = $totals;
    }

    public function array(): array
    {
        return $this->data;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                if (!empty($this->totals)) {
                    $row = count($this->data) + 2; 
                    $column = 'A';
                    foreach ($this->headings as $index => $heading) {
                        $column = chr(65 + $index); 
                        if (in_array($heading, array_keys($this->totals))) {
                            $total = $this->totals[$heading];
                            $event->sheet->setCellValue($column . $row, $total);
                        }
                    }
                    $event->sheet->getStyle('A'.$row.':'.$column.$row)->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ]
                    ]);
                }
            }
        ];
    }
}