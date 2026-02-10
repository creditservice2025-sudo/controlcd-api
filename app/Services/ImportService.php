<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Credit;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Response;

class ImportService
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function importFromCsv($filePath, $sellerIdInput)
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \Exception("El archivo no existe o no se puede leer: $filePath");
        }

        // Validate seller exists once
        $seller = Seller::find($sellerIdInput);
        if (!$seller) {
            throw new \Exception("Vendedor ID $sellerIdInput no encontrado.");
        }

        $handle = fopen($filePath, 'r');
        $headers = fgetcsv($handle, 0, ',');

        // Clean headers (trim BOM, whitespace)
        $headers = array_map(function($h) {
            return trim(preg_replace('/\x{FEFF}/u', '', $h));
        }, $headers);

        // Basic header validation (loose check)
        $requiredHeaders = ['cliente_nombre', 'cliente_dni', 'monto_credito']; // fecha_primera_cuota is now optional
        foreach ($requiredHeaders as $req) {
            if (!in_array($req, $headers)) {
                throw new \Exception("Falta el encabezado requerido: $req");
            }
        }

        $results = [
            'success' => 0,
            'errors' => [],
            'row' => 1
        ];

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $results['row']++;
            
            // Check if row is empty
            if (empty(array_filter($data))) {
                continue;
            }

            // Check column count mismatch
            if (count($headers) !== count($data)) {
                 $results['errors'][] = [
                    'line' => $results['row'],
                    'field' => 'Estructura',
                    'error' => "La fila tiene " . count($data) . " columnas, se esperaban " . count($headers)
                ];
                continue;
            }

            $row = array_combine($headers, $data);

            // Validation Rules
            $validator = \Illuminate\Support\Facades\Validator::make($row, [
                'cliente_nombre' => 'required|string|max:255',
                'cliente_dni' => 'required',
                'monto_credito' => 'required|numeric|min:0',
                'fecha_primera_cuota' => 'nullable|date_format:Y-m-d', // Made nullable/optional
                'cuotas_numero' => 'nullable|integer|min:1',
                'tasa_interes' => 'nullable|numeric|min:0',
                'frecuencia' => 'nullable|in:Diaria,Semanal,Quincenal,Mensual',
                'fecha_entrega' => 'nullable|date_format:Y-m-d'
            ], [
                'cliente_nombre.required' => 'El nombre del cliente es obligatorio',
                'cliente_dni.required' => 'El DNI es obligatorio',
                'monto_credito.numeric' => 'El monto del crédito debe ser un número',
                'fecha_primera_cuota.date_format' => 'La fecha de primera cuota debe ser YYYY-MM-DD',
                'frecuencia.in' => 'La frecuencia debe ser: Diaria, Semanal, Quincenal o Mensual'
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    foreach ($messages as $msg) {
                        $results['errors'][] = [
                            'line' => $results['row'],
                            'field' => $field,
                            'error' => $msg
                        ];
                    }
                }
                continue; // Skip this row
            }

            DB::beginTransaction();
            try {
                $this->processRow($row, $seller);
                DB::commit();
                $results['success']++;
            } catch (\Exception $e) {
                DB::rollBack();
                $results['errors'][] = [
                    'line' => $results['row'],
                    'field' => 'Procesamiento',
                    'error' => $e->getMessage()
                ];
                Log::error("Error importando fila {$results['row']}: " . $e->getMessage());
            }
        }

        fclose($handle);
        return $results;
    }

    public function importFromExcel($filePath, $sellerIdInput)
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \Exception("El archivo no existe o no se puede leer: $filePath");
        }

        $seller = Seller::find($sellerIdInput);
        if (!$seller) {
            throw new \Exception("Vendedor ID $sellerIdInput no encontrado.");
        }

        $rows = Excel::toArray([], $filePath)[0];
        if (empty($rows)) {
            throw new \Exception("El archivo de Excel está vacío.");
        }

        $headers = array_shift($rows);
        $headers = array_map(function($h) {
            return trim(preg_replace('/\x{FEFF}/u', '', $h));
        }, $headers);

        $results = [
            'success' => 0,
            'errors' => [],
            'row' => 1
        ];

        foreach ($rows as $data) {
            $results['row']++;
            
            if (empty(array_filter($data))) {
                continue;
            }

            if (count($headers) !== count($data)) {
                 $results['errors'][] = [
                    'line' => $results['row'],
                    'field' => 'Estructura',
                    'error' => "Columnas desiguales en la fila."
                ];
                continue;
            }

            $row = array_combine($headers, $data);

            DB::beginTransaction();
            try {
                $this->processRow($row, $seller);
                DB::commit();
                $results['success']++;
            } catch (\Exception $e) {
                DB::rollBack();
                $results['errors'][] = [
                    'line' => $results['row'],
                    'field' => 'Procesamiento',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    public function downloadExcelTemplate($sellerId = null)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Generate dynamic filename
        $filename = 'plantilla_importacion';
        if ($sellerId) {
            $seller = Seller::with('user')->find($sellerId);
            if ($seller && $seller->user) {
                $sellerName = str_replace(' ', '_', $seller->user->name);
                $date = Carbon::now()->format('Ymd');
                
                // Get download counter (you can implement a database counter if needed)
                // For simplicity, using timestamp as counter
                $counter = Carbon::now()->format('His');
                
                $filename = "{$sellerName}_{$date}_{$counter}";
            }
        }
        
        // Define headers in logical order
        $headers = [
            'A' => 'cliente_nombre',
            'B' => 'cliente_dni',
            'C' => 'cliente_telefono',
            'D' => 'monto_credito',
            'E' => 'tasa_interes',
            'F' => 'cuotas_numero',
            'G' => 'fecha_entrega',
            'H' => 'frecuencia',
            'I' => 'fecha_primera_cuota',
            'J' => 'microseguro_porcentaje',
            'K' => 'pagos_realizados',
            'L' => 'excluir_domingos'
        ];
        
        // Set headers
        foreach ($headers as $col => $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $sheet->getStyle($col . '1')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('4A90E2');
            $sheet->getStyle($col . '1')->getFont()->getColor()->setRGB('FFFFFF');
        }
        
        // Add example data in row 2
        $today = Carbon::now()->format('Y-m-d');
        $sheet->setCellValue('A2', 'Juan Pérez');
        $sheet->setCellValue('B2', '12345678');
        $sheet->setCellValue('C2', '0987654321');
        $sheet->setCellValue('D2', 1000);
        $sheet->setCellValue('E2', 20);
        $sheet->setCellValue('F2', 24);
        $sheet->setCellValue('G2', $today);
        $sheet->setCellValue('H2', 'Diaria');
        // I2 will have formula
        $sheet->setCellValue('J2', 5); // 5% microseguro
        $sheet->setCellValue('K2', 0);
        $sheet->setCellValue('L2', 'SI');
        
        // Data validation for FRECUENCIA (column H)
        $frequencyValidation = $sheet->getCell('H2')->getDataValidation();
        $frequencyValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $frequencyValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
        $frequencyValidation->setAllowBlank(false);
        $frequencyValidation->setShowInputMessage(true);
        $frequencyValidation->setShowErrorMessage(true);
        $frequencyValidation->setShowDropDown(true);
        $frequencyValidation->setErrorTitle('Error');
        $frequencyValidation->setError('Debe seleccionar una frecuencia válida');
        $frequencyValidation->setPromptTitle('Frecuencia de Pago');
        $frequencyValidation->setPrompt('Seleccione la frecuencia de pago del crédito');
        $frequencyValidation->setFormula1('"Diaria,Semanal,Quincenal,Mensual"');
        
        // Data validation for EXCLUIR_DOMINGOS (column L)
        $sundayValidation = $sheet->getCell('L2')->getDataValidation();
        $sundayValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $sundayValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
        $sundayValidation->setAllowBlank(false);
        $sundayValidation->setShowInputMessage(true);
        $sundayValidation->setShowErrorMessage(true);
        $sundayValidation->setShowDropDown(true);
        $sundayValidation->setErrorTitle('Error');
        $sundayValidation->setError('Debe seleccionar SI o NO');
        $sundayValidation->setPromptTitle('Excluir Domingos');
        $sundayValidation->setPrompt('¿Excluir domingos del calendario de pagos?');
        $sundayValidation->setFormula1('"SI,NO"');
        
        // Formula for fecha_primera_cuota (column I2)
        // This formula adds days based on frequency and skips Sundays if needed
        // =IF(H2="Diaria",G2+1,IF(H2="Semanal",G2+7,IF(H2="Quincenal",G2+15,IF(H2="Mensual",G2+30,G2+1))))
        // We'll create a simpler version that the user can adjust
        $formula = '=IF(L2="SI",IF(WEEKDAY(IF(H2="Diaria",G2+1,IF(H2="Semanal",G2+7,IF(H2="Quincenal",G2+15,G2+30))))=1,IF(H2="Diaria",G2+2,IF(H2="Semanal",G2+8,IF(H2="Quincenal",G2+16,G2+31))),IF(H2="Diaria",G2+1,IF(H2="Semanal",G2+7,IF(H2="Quincenal",G2+15,G2+30)))),IF(H2="Diaria",G2+1,IF(H2="Semanal",G2+7,IF(H2="Quincenal",G2+15,G2+30))))';
        $sheet->setCellValue('I2', $formula);
        
        // Format date columns
        $sheet->getStyle('G2')->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_YYYYMMDD2);
        $sheet->getStyle('I2')->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_YYYYMMDD2);
        
        // Auto-size columns
        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Add instructions in a separate sheet
        $instructionsSheet = $spreadsheet->createSheet();
        $instructionsSheet->setTitle('Instrucciones');
        $instructionsSheet->setCellValue('A1', 'INSTRUCCIONES DE LLENADO');
        $instructionsSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        
        $instructions = [
            '',
            '1. cliente_nombre: Nombre completo del cliente',
            '2. cliente_dni: Documento de identidad (solo números)',
            '3. cliente_telefono: Teléfono de contacto',
            '4. monto_credito: Monto del crédito en números',
            '5. tasa_interes: Tasa de interés (%) del crédito',
            '6. cuotas_numero: Cantidad de cuotas del crédito',
            '7. fecha_entrega: Fecha de entrega del crédito (YYYY-MM-DD)',
            '8. frecuencia: Usar lista desplegable (Diaria/Semanal/Quincenal/Mensual)',
            '9. fecha_primera_cuota: SE CALCULA AUTOMÁTICAMENTE con fórmula',
            '10. microseguro_porcentaje: Porcentaje de microseguro (ej: 5 para 5%)',
            '11. pagos_realizados: Monto de pagos históricos ya realizados',
            '12. excluir_domingos: Usar lista desplegable (SI/NO)',
            '',
            'NOTAS IMPORTANTES:',
            '- La fecha_primera_cuota se calcula automáticamente basándose en fecha_entrega + frecuencia',
            '- Si excluir_domingos = SI, la fórmula salta domingos automáticamente',
            '- Para agregar más filas, copie la fila 2 y pegue abajo (mantendrá fórmulas y validaciones)',
            '- NO modifique los encabezados de la primera fila',
            '',
            'VALIDACIÓN DE DUPLICADOS:',
            '- NO se permite duplicar cédula (DNI) para un mismo vendedor',
            '- El sistema validará y rechazará filas con DNI duplicados',
            '',
            'MÚLTIPLES CRÉDITOS POR CLIENTE:',
            '- Si un cliente tiene varios créditos, agregue UNA FILA POR CADA CRÉDITO',
            '- Use la MISMA cédula y nombre en cada fila',
            '- Cada fila representa UN crédito diferente con sus propios parámetros',
            '- Ejemplo: Juan (DNI 12345678) tiene 3 créditos = 3 filas con el mismo DNI',
        ];
        
        $row = 2;
        foreach ($instructions as $instruction) {
            $instructionsSheet->setCellValue('A' . $row, $instruction);
            $row++;
        }
        $instructionsSheet->getColumnDimension('A')->setWidth(100);
        
        // Set active sheet back to data
        $spreadsheet->setActiveSheetIndex(0);
        
        // Create writer and download
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $temp_file = tempnam(sys_get_temp_dir(), 'template_');
        $writer->save($temp_file);
        
        return response()->download($temp_file, $filename . '.xlsx')->deleteFileAfterSend(true);
    }

    protected function processRow($row, $seller)
    {
        $sellerId = $seller->id;

        // 1. Find or Create Client
        // If client exists with this DNI for this seller, use it
        // If not, create a new client
        $client = Client::where('dni', $row['cliente_dni'])
                        ->where('seller_id', $sellerId)
                        ->first();

        if (!$client) {
            // Client does not exist, create it
            $client = Client::create([
                'uuid' => (string) Str::uuid(),
                'name' => $row['cliente_nombre'],
                'dni' => $row['cliente_dni'],
                'phone' => $row['cliente_telefono'] ?? '0',
                'seller_id' => $sellerId,
                'status' => 'active',
                'needs_update' => true, // Mark for update (Address, GPS, Photos might be missing)
                'address' => '',
                'geolocation' => ['latitude' => 0, 'longitude' => 0],
                'routing_order' => Client::where('seller_id', $sellerId)->max('routing_order') + 1,
            ]);
        }
        // If client already exists, we just use it and create a new credit for them

        // 2. Create Credit
        $creditValue = floatval($row['monto_credito']);
        $interestRate = floatval($row['tasa_interes'] ?? 20);
        $installmentsCount = intval($row['cuotas_numero'] ?? 24);
        $frequency = $row['frecuencia'] ?? 'Diaria';
        $payoutDate = $row['fecha_entrega'] ?? Carbon::now()->format('Y-m-d');
        
        // Automated First Quota Date logic if missing
        $firstQuotaDate = $row['fecha_primera_cuota'] ?? null;
        $excludeSundays = ($row['excluir_domingos'] ?? 'SI') === 'SI';

        if (empty($firstQuotaDate)) {
            $date = Carbon::parse($payoutDate);
            $date->addDay(); // Default to tomorrow for all frequencies initially, or add frequency?
            // User requested "mañana" for daily, but let's be robust:
            /*
            switch ($frequency) {
                case 'Diaria': $date->addDay(); break;
                case 'Semanal': $date->addWeek(); break;
                default: $date->addDay();
            }
            */
            
            // Skip Sundays if requested
            if ($excludeSundays) {
                while ($date->dayOfWeek === Carbon::SUNDAY) {
                    $date->addDay();
                }
            }
            $firstQuotaDate = $date->format('Y-m-d');
        }
        
        // Calculate microseguro amount from percentage
        $microInsurancePercentage = floatval($row['microseguro_porcentaje'] ?? 0);
        $microInsuranceAmount = ($creditValue * $microInsurancePercentage) / 100;

        $interestAmount = ($creditValue * $interestRate) / 100;
        $totalAmount = $creditValue + $interestAmount;

        $credit = Credit::create([
            'client_id' => $client->id,
            'seller_id' => $sellerId,
            'credit_value' => $creditValue,
            'total_interest' => $interestRate,
            'total_amount' => $totalAmount,
            'number_installments' => $installmentsCount,
            'payment_frequency' => $frequency,
            'status' => 'Vigente',
            'start_date' => $payoutDate,
            'first_quota_date' => $firstQuotaDate,
            'micro_insurance_amount' => $microInsuranceAmount,
            'excluded_days' => $excludeSundays ? json_encode(['Domingo']) : json_encode([]),
        ]);

        // 3. Generate Installments
        $this->generateInstallments($credit);

        // 4. Handle Historical Payments
        $paidAmount = floatval($row['pagos_realizados'] ?? 0);
        if ($paidAmount > 0) {
            $payment = Payment::create([
                'credit_id' => $credit->id,
                'user_id' => $seller->user_id ?? 1,
                'amount' => $paidAmount,
                'unapplied_amount' => $paidAmount,
                'payment_date' => $payoutDate, // Asumimos fecha de entrega para no afectar liquidaciones futuras si es histórico
                'payment_method' => 'Efectivo',
                'status' => 'Pagado',
                'description' => 'Pago histórico importado',
                'business_date' => $payoutDate,
            ]);

            // Apply payment to installments (FIFO)
            $this->paymentService->reapplyPayments($credit->id);
        }

        return $credit;
    }

    protected function generateInstallments($credit)
    {
        $quotaAmount = $credit->total_amount / $credit->number_installments;
        $excludedDayNames = json_decode($credit->excluded_days, true) ?? [];
        
        $dayMap = [
            'Domingo' => Carbon::SUNDAY,
            'Lunes' => Carbon::MONDAY,
            'Martes' => Carbon::TUESDAY,
            'Miércoles' => Carbon::WEDNESDAY,
            'Jueves' => Carbon::THURSDAY,
            'Viernes' => Carbon::FRIDAY,
            'Sábado' => Carbon::SATURDAY
        ];

        $excludedDayNumbers = [];
        foreach ($excludedDayNames as $dayName) {
            if (isset($dayMap[$dayName])) {
                $excludedDayNumbers[] = $dayMap[$dayName];
            }
        }

        $adjustForExcludedDays = function ($date) use ($excludedDayNumbers) {
            while (in_array($date->dayOfWeek, $excludedDayNumbers)) {
                $date->addDay();
            }
            return $date;
        };

        $dueDate = $adjustForExcludedDays(Carbon::parse($credit->first_quota_date));

        for ($i = 1; $i <= $credit->number_installments; $i++) {
            Installment::create([
                'credit_id' => $credit->id,
                'quota_number' => $i,
                'due_date' => $dueDate->format('Y-m-d'),
                'quota_amount' => round($quotaAmount, 2),
                'status' => 'Pendiente',
            ]);

            if ($i < $credit->number_installments) {
                switch ($credit->payment_frequency) {
                    case 'Diaria': $dueDate->addDay(); break;
                    case 'Semanal': $dueDate->addWeek(); break;
                    case 'Quincenal': $dueDate->addDays(15); break;
                    case 'Mensual': $dueDate->addMonth(); break;
                    default: $dueDate->addMonth();
                }
                $dueDate = $adjustForExcludedDays($dueDate);
            }
        }
    }
}
