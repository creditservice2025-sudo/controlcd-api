<?php
namespace App\Exports;

use App\Models\Liquidation;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Events\AfterSheet;

class LiquidationExport implements FromArray, WithHeadings, WithStyles, WithColumnWidths, WithEvents
{
    protected $reportData;

    public function __construct($reportData)
    {
        $this->reportData = $reportData;
    }

    public function array(): array
    {
        $data = [];
        // Encabezado
        $data[] = ['CIERRE APLICADO DEL CUADRE DIARIO DE LISTADO DE RUTA ' . ($this->reportData['seller'] ? strtoupper($this->reportData['seller']->city->name) : 'GENERAL')];
        $data[] = ['Cobrador Encargado de este Cierre: ' . ($this->reportData['user'] ? $this->reportData['user']->name : 'TODOS')];
        $data[] = ['FECHA DEL CIERRE: ' . ($this->reportData['report_date'] ?? '')];
        // Pagos del día
        $data[] = ['Pagos del día'];
        $data[] = ['No.', 'Cliente', 'Crédito', 'Frecuencia', 'Vr. Cuota', 'Saldo Actual', 'Vr. Pago Hoy', 'Hora'];
        $total_paid_today = 0;
        if (count($this->reportData['report_data'] ?? []) === 0) {
            $data[] = ['No hay pagos para la fecha.', '', '', '', '', '', '', ''];
        } else {
            foreach ($this->reportData['report_data'] ?? [] as $item) {
                $data[] = [
                    $item['no'],
                    $item['client_name'],
                    '#00' . $item['credit_id'],
                    $item['payment_frequency'],
                    number_format($item['quota_amount'], 2),
                    number_format($item['remaining_amount'], 2),
                    number_format($item['paid_today'], 2),
                    $item['payment_time'] ?? 'N/A',
                ];
                $total_paid_today += $item['paid_today'];
            }
        }
        $data[] = ['', '', '', '', '', 'TOTAL DE PAGOS', number_format($total_paid_today, 2), ''];
        // Créditos nuevos
        $data[] = ['LISTADO DE CRÉDITOS NUEVOS DENTRO DEL COBRO'];
        $data[] = ['No.', 'Cliente', 'Crédito', 'F. Pago', 'V.C + U'];
        if (count($this->reportData['new_credits'] ?? []) === 0) {
            $data[] = ['No hay créditos nuevos para la fecha.', '', '', '', ''];
        } else {
            $total_new_credits_value = 0;
            foreach ($this->reportData['new_credits'] as $index => $credit) {
                $utilidad = $credit->credit_value * ($credit->total_interest / 100);
                $total = $credit->credit_value + $utilidad;
                $data[] = [
                    $index + 1,
                    $credit->client->name,
                    '#00' . $credit->id,
                    $credit->payment_frequency,
                    '$' . number_format($credit->credit_value, 2) . ' + $' . number_format($utilidad, 2) . ' = $' . number_format($total, 2),
                ];
                $total_new_credits_value += $credit->credit_value;
            }
            $data[] = ['', '', '', 'TOTAL CRÉDITOS NUEVOS', '$' . number_format($total_new_credits_value, 2)];
        }
        // Microseguros
        $totalMicroinsuranceNewCredits = 0;
        $creditsWithMicroinsurance = [];
        foreach ($this->reportData['new_credits'] ?? [] as $credit) {
            if ($credit->micro_insurance_percentage > 0) {
                $microinsuranceValue = ($credit->micro_insurance_percentage * $credit->credit_value) / 100;
                $totalMicroinsuranceNewCredits += $microinsuranceValue;
                $creditsWithMicroinsurance[] = $credit;
            }
        }
        if (count($creditsWithMicroinsurance) > 0) {
            $data[] = ['LISTADO DE MICROSEGUROS EN CRÉDITOS NUEVOS'];
            $data[] = ['No.', 'Cliente', 'Crédito', 'V.C', '% Microseguro', 'Vr. Microseguro'];
            $totalMicroinsurance = 0;
            foreach ($creditsWithMicroinsurance as $index => $credit) {
                $microinsuranceValue = ($credit->micro_insurance_percentage * $credit->credit_value) / 100;
                $totalMicroinsurance += $microinsuranceValue;
                $data[] = [
                    $index + 1,
                    $credit->client->name,
                    '#00' . $credit->id,
                    '$' . number_format($credit->credit_value, 2),
                    number_format($credit->micro_insurance_percentage, 2) . '%',
                    '$' . number_format($microinsuranceValue, 2),
                ];
            }
            $data[] = ['', '', '', '', 'TOTAL MICROSEGUROS', '$' . number_format($totalMicroinsurance, 2)];
        }
        // Gastos
        $data[] = ['LISTADO DE GASTOS DENTRO DEL COBRO'];
        $data[] = ['No.', 'Descripción', 'Categoría', 'Vr. Gasto'];
        if (!isset($this->reportData['expenses']) || count($this->reportData['expenses']) === 0) {
            $data[] = ['No hay gastos para la fecha.', '', '', ''];
        } else {
            $total_expenses_value = 0;
            foreach ($this->reportData['expenses'] as $index => $expense) {
                $data[] = [
                    $index + 1,
                    $expense->description,
                    $expense->category->name ?? 'N/A',
                    '$' . number_format($expense->value, 2),
                ];
                $total_expenses_value += $expense->value;
            }
            $data[] = ['', '', 'TOTAL DE GASTOS', '$' . number_format($total_expenses_value, 2)];
        }
        // Ingresos
        $data[] = ['LISTADO DE INGRESOS DENTRO DEL COBRO'];
        $data[] = ['No.', 'Descripción', 'Vr. Ingreso'];
        if (!isset($this->reportData['incomes']) || count($this->reportData['incomes']) === 0) {
            $data[] = ['No hay ingresos para la fecha.', '', ''];
        } else {
            $total_incomes_value = 0;
            foreach ($this->reportData['incomes'] as $index => $income) {
                $data[] = [
                    $index + 1,
                    $income->description,
                    '$' . number_format($income->value, 2),
                ];
                $total_incomes_value += $income->value;
            }
            $data[] = ['', 'TOTAL DE INGRESOS', '$' . number_format($total_incomes_value, 2)];
        }
        // Resumen
        $data[] = ['Resumen'];
        $data[] = ['TOTAL RECIBOS EN RUTA', $this->reportData['total_credits'] ?? 0];
        $data[] = ['RECIBOS CON PAGOS', $this->reportData['with_payment'] ?? 0];
        $data[] = ['RECIBOS SIN PAGOS', $this->reportData['without_payment'] ?? 0];
        $data[] = ['TOTAL RECAUDADO', '$' . number_format($this->reportData['total_collected'] ?? 0, 2)];
        $data[] = ['RECAUDO MICROSEGURO', '$' . number_format($totalMicroinsuranceNewCredits, 2)];
        if (isset($this->reportData['total_incomes'])) {
            $data[] = ['TOTAL INGRESOS', '$' . number_format($this->reportData['total_incomes'], 2)];
        }
        if (isset($this->reportData['total_expenses'])) {
            $data[] = ['TOTAL GASTOS', '$' . number_format($this->reportData['total_expenses'], 2)];
        }
        if (count($this->reportData['new_credits'] ?? []) > 0) {
            $data[] = ['No. CRÉDITOS NUEVOS', count($this->reportData['new_credits'])];
        }
        $data[] = ['_________________________'];
        $data[] = ['FIRMA RECAUDADOR'];
        $data[] = ['_________________________'];
        $data[] = ['FIRMA COBRADOR'];
        return $data;
    }

    public function headings(): array
    {
        return [];
    }

    public function styles(Worksheet $sheet)
    {
        // Negrita y centrado en títulos
        foreach ([1,2,3,5] as $row) {
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal('center');
            $sheet->getRowDimension($row)->setRowHeight(28);
        }
        // Negrita y centrado en los títulos de cada bloque
        foreach ($sheet->getRowIterator() as $row) {
            $rowIdx = $row->getRowIndex();
            $cellValue = $sheet->getCell('A'.$rowIdx)->getValue();
            if (is_string($cellValue) && (
                str_contains($cellValue, 'Pagos del día') ||
                str_contains($cellValue, 'LISTADO DE CRÉDITOS NUEVOS') ||
                str_contains($cellValue, 'LISTADO DE MICROSEGUROS') ||
                str_contains($cellValue, 'LISTADO DE GASTOS') ||
                str_contains($cellValue, 'LISTADO DE INGRESOS') ||
                str_contains($cellValue, 'Resumen')
            )) {
                $sheet->getStyle('A'.$rowIdx)->getFont()->setBold(true);
                $sheet->getStyle('A'.$rowIdx)->getAlignment()->setHorizontal('center');
                $sheet->getRowDimension($rowIdx)->setRowHeight(24);
            }
            // Centrado y negrita en totales
            if (is_string($cellValue) && (
                str_contains($cellValue, 'TOTAL DE PAGOS') ||
                str_contains($cellValue, 'TOTAL CRÉDITOS NUEVOS') ||
                str_contains($cellValue, 'TOTAL MICROSEGUROS') ||
                str_contains($cellValue, 'TOTAL DE GASTOS') ||
                str_contains($cellValue, 'TOTAL DE INGRESOS') ||
                str_contains($cellValue, 'TOTAL RECIBOS EN RUTA') ||
                str_contains($cellValue, 'RECIBOS CON PAGOS') ||
                str_contains($cellValue, 'RECIBOS SIN PAGOS') ||
                str_contains($cellValue, 'TOTAL RECAUDADO') ||
                str_contains($cellValue, 'RECAUDO MICROSEGURO') ||
                str_contains($cellValue, 'TOTAL INGRESOS') ||
                str_contains($cellValue, 'TOTAL GASTOS') ||
                str_contains($cellValue, 'No. CRÉDITOS NUEVOS')
            )) {
                $sheet->getStyle('A'.$rowIdx.':H'.$rowIdx)->getFont()->setBold(true);
                $sheet->getStyle('A'.$rowIdx.':H'.$rowIdx)->getAlignment()->setHorizontal('center');
                $sheet->getRowDimension($rowIdx)->setRowHeight(22);
            }
        }
        // Bordes en todas las tablas
        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();
        $sheet->getStyle('A1:'.$highestCol.$highestRow)
            ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        // Más espacio en todas las filas de datos
        for ($i = 1; $i <= $highestRow; $i++) {
            $sheet->getRowDimension($i)->setRowHeight(20);
        }
        return [];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 8,   // No.
            'B' => 28,  // Cliente
            'C' => 16,  // Crédito
            'D' => 18,  // Frecuencia/F. Pago
            'E' => 38,  // Vr. Cuota / V.C + U
            'F' => 18,  // Saldo Actual / V.C / % Microseguro
            'G' => 18,  // Vr. Pago Hoy / Vr. Microseguro
            'H' => 14,  // Hora
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet;
                foreach ($sheet->getRowIterator() as $row) {
                    $rowIdx = $row->getRowIndex();
                    $cellValue = $sheet->getCell('A'.$rowIdx)->getValue();
                    // Solo combinar títulos de bloque y firmas
                    if (is_string($cellValue) && (
                        str_contains($cellValue, 'CIERRE APLICADO DEL CUADRE DIARIO') ||
                        str_contains($cellValue, 'Cobrador Encargado de este Cierre') ||
                        str_contains($cellValue, 'FECHA DEL CIERRE') ||
                        str_contains($cellValue, 'Pagos del día') ||
                        str_contains($cellValue, 'LISTADO DE CRÉDITOS NUEVOS') ||
                        str_contains($cellValue, 'LISTADO DE MICROSEGUROS') ||
                        str_contains($cellValue, 'LISTADO DE GASTOS') ||
                        str_contains($cellValue, 'LISTADO DE INGRESOS') ||
                        str_contains($cellValue, 'Resumen') ||
                        str_contains($cellValue, '_________________________') ||
                        str_contains($cellValue, 'FIRMA RECAUDADOR') ||
                        str_contains($cellValue, 'FIRMA COBRADOR')
                    )) {
                        $sheet->mergeCells('A'.$rowIdx.':H'.$rowIdx);
                    }
                }
            }
        ];
    }
}
