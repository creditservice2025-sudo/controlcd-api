<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Excel del resumen general, armado desde la misma matriz que el PDF y que la
 * pantalla.
 *
 * A diferencia de AccumulatedByCityExport -que consulta otra agregación y
 * estiliza celda por celda-, acá el estilo se aplica por rangos: con 20 rutas y
 * 10 columnas la diferencia se nota al descargar.
 */
class SummaryReportExport implements FromArray, WithEvents
{
    public function __construct(private array $data)
    {
    }

    public function array(): array
    {
        $leyenda = $this->data['tone_legend'] ?? [];

        $filas = [
            ['Controll CD'],
            [$this->data['title']],
            [$this->data['subtitle'] . ' · Generado el ' . $this->data['generated_at']],
            // La fila 4 estaba en blanco y ahora lleva la referencia de colores.
            // Va ACÁ y no al pie del reporte a propósito: agregada abajo correría
            // las filas de totales, cuyo estilo se calcula desde la última fila
            // de la hoja, y el formato terminaría pintando lo que no es.
            $leyenda
                ? array_merge(['Recaudo del período'], array_column($leyenda, 'etiqueta'))
                : [],
            $this->data['columns'],
        ];

        foreach ($this->data['rows'] as $fila) {
            $filas[] = $fila;
        }

        if (count($this->data['totals'])) {
            $filas[] = [];
            foreach ($this->data['totals'] as $fila) {
                $filas[] = $fila;
            }
        }

        return $filas;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $hoja = $event->sheet;
                $ultimaColumna = $hoja->getHighestColumn();
                $ultimaFila = $hoja->getHighestRow();
                $filaEncabezado = 5;

                $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $hoja->getStyle('A1')->getFont()->getColor()->setARGB('FF2B69E8');
                $hoja->getStyle('A2')->getFont()->setBold(true)->setSize(11);
                $hoja->getStyle('A3')->getFont()->setSize(9)->getColor()->setARGB('FF667085');

                $hoja->getStyle("A{$filaEncabezado}:{$ultimaColumna}{$filaEncabezado}")
                    ->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                $hoja->getStyle("A{$filaEncabezado}:{$ultimaColumna}{$filaEncabezado}")
                    ->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FF2B69E8');

                // Referencia de colores en la fila 4, con los mismos tonos que
                // después pintan las filas.
                $leyenda = $this->data['tone_legend'] ?? [];
                if (count($leyenda)) {
                    $hoja->getStyle('A4')->getFont()->setSize(8)
                        ->getColor()->setARGB('FF667085');

                    foreach ($leyenda as $i => $tramo) {
                        // B, C, D, E: arranca en la segunda columna porque la
                        // primera lleva el rótulo.
                        $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 2);
                        $celda = $hoja->getStyle("{$letra}4");
                        $celda->getFont()->setBold(true)->setSize(8)
                            ->getColor()->setARGB('FF' . $tramo['texto']);
                        $celda->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setARGB('FF' . $tramo['fondo']);
                        $celda->getAlignment()->setHorizontal('center');
                    }
                }

                // Banda de color por vendedor: las mismas tres primeras columnas
                // que en pantalla —Vendedor, Moneda, Total Recaudado— y los
                // mismos cortes. Fila por fila, porque cada una puede caer en un
                // tramo distinto; no hay rango que estilizar de una sola vez.
                foreach (($this->data['row_tones'] ?? []) as $i => $tono) {
                    $fila = $filaEncabezado + 1 + $i;
                    $estilo = $hoja->getStyle("A{$fila}:C{$fila}");
                    $estilo->getFont()->setBold(true)
                        ->getColor()->setARGB('FF' . \App\Support\EscalaRecaudo::texto($tono));
                    $estilo->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FF' . \App\Support\EscalaRecaudo::fondo($tono));
                }

                // Formato de miles con dos decimales en las columnas de dinero.
                foreach ($this->data['money_columns'] as $indice) {
                    $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($indice + 1);
                    $hoja->getStyle("{$letra}" . ($filaEncabezado + 1) . ":{$letra}{$ultimaFila}")
                        ->getNumberFormat()->setFormatCode('#,##0.00');
                }

                // Los totales, en negrita: son las últimas filas.
                $cantidadTotales = count($this->data['totals']);
                if ($cantidadTotales > 0) {
                    $desde = $ultimaFila - $cantidadTotales + 1;
                    $hoja->getStyle("A{$desde}:{$ultimaColumna}{$ultimaFila}")->getFont()->setBold(true);
                    $hoja->getStyle("A{$desde}:{$ultimaColumna}{$ultimaFila}")
                        ->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFF4F7FB');
                }

                $indiceUltima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ultimaColumna);
                for ($i = 1; $i <= $indiceUltima; $i++) {
                    $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
                    $hoja->getColumnDimension($letra)->setAutoSize(true);
                }

                $hoja->freezePane('A' . ($filaEncabezado + 1));
            },
        ];
    }
}
