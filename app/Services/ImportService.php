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

    public function analyzeFile($filePath, $sellerId, $extension)
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \Exception("El archivo no existe o no se puede leer.");
        }

        if (in_array(strtolower($extension), ['xlsx', 'xls'])) {
            $allRows = Excel::toArray([], $filePath)[0];
            $headers = array_shift($allRows);
            $rows = [];
            foreach ($allRows as $row) {
                if (!empty(array_filter($row, function($cell) { return $cell !== null && $cell !== ''; }))) {
                    $rows[] = $row;
                }
            }
        } else {
            $handle = fopen($filePath, 'r');
            $headers = fgetcsv($handle, 0, ',');
            $rows = [];
            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                if (!empty(array_filter($data))) {
                    $rows[] = $data;
                }
            }
            fclose($handle);
        }

        $headers = array_map(function($h) {
            return trim(preg_replace('/\x{FEFF}/u', '', $h));
        }, $headers);

        $dniIndex = array_search('cliente_dni', $headers);
        $nameIndex = array_search('cliente_nombre', $headers);
        $amountIndex = array_search('monto_credito', $headers);
        $phoneIndex = array_search('cliente_telefono', $headers);
        
        if ($dniIndex === false) {
            throw new \Exception("El archivo no contiene la columna 'cliente_dni'.");
        }

        $summary = [
            'total_rows' => count($rows),
            'existing_clients' => 0,
            'new_clients' => 0,
            'duplicate_entries_in_file' => 0,
            'client_details' => [] // Detailed info for each unique client in file
        ];

        $clientsInFile = [];
        $phonesInFile = []; // phone => dni
        foreach ($rows as $rowData) {
            if (count($rowData) <= $dniIndex) continue;
            
            $dni = trim($rowData[$dniIndex] ?? '');
            if (empty($dni)) continue;

            $rawName = ($nameIndex !== false && isset($rowData[$nameIndex])) ? trim($rowData[$nameIndex]) : 'S/N';
            // Force Title Case
            $name = \mb_convert_case(mb_strtolower($rawName, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
            $amount = ($amountIndex !== false && isset($rowData[$amountIndex])) ? floatval($rowData[$amountIndex]) : 0;
            $phone = ($phoneIndex !== false && isset($rowData[$phoneIndex])) ? trim($rowData[$phoneIndex]) : '';

            if (!isset($clientsInFile[$dni])) {
                $clientsInFile[$dni] = [
                    'dni' => $dni,
                    'name' => $name,
                    'phone' => $phone,
                    'is_new' => true,
                    'credits' => [],
                    'total_amount' => 0,
                    'warnings' => []
                ];
            } else {
                // Intra-file name check
                if (strcasecmp($clientsInFile[$dni]['name'], $name) !== 0) {
                    $mismatch = "ERROR: La cédula $dni aparece con nombres distintos en el archivo: '{$clientsInFile[$dni]['name']}' y '$name'.";
                    if (!in_array($mismatch, $clientsInFile[$dni]['warnings'])) {
                        $clientsInFile[$dni]['warnings'][] = $mismatch;
                    }
                    $clientsInFile[$dni]['has_errors'] = true;
                    // Update main name to show conflict clearly
                    if (!str_contains($clientsInFile[$dni]['name'], 'CONFLICTO')) {
                        $clientsInFile[$dni]['name'] = "(!) CONFLICTO: " . $clientsInFile[$dni]['name'] . " / " . $name;
                    }
                }
                // Intra-file phone consistency check
                if ($clientsInFile[$dni]['phone'] !== $phone) {
                    $mismatch = "ERROR: La cédula $dni aparece con teléfonos distintos: '{$clientsInFile[$dni]['phone']}' y '$phone'.";
                    if (!in_array($mismatch, $clientsInFile[$dni]['warnings'])) {
                        $clientsInFile[$dni]['warnings'][] = $mismatch;
                    }
                    $clientsInFile[$dni]['has_errors'] = true;
                }
            }

            // Flag if phone is used by a different DNI in same file
            if (!empty($phone)) {
                if (isset($phonesInFile[$phone]) && $phonesInFile[$phone] !== $dni) {
                    $error = "ERROR: El teléfono $phone ya está siendo usado por otra cédula en este archivo (DNI: {$phonesInFile[$phone]}).";
                    if (!in_array($error, $clientsInFile[$dni]['warnings'])) {
                        $clientsInFile[$dni]['warnings'][] = $error;
                    }
                    $clientsInFile[$dni]['has_errors'] = true;
                }
                $phonesInFile[$phone] = $dni;
            }

            $clientsInFile[$dni]['credits'][] = $amount;
            $clientsInFile[$dni]['total_amount'] += $amount;
        }

        foreach ($clientsInFile as $dni => $data) {
            $client = Client::where('dni', $dni)
                            ->where('seller_id', $sellerId)
                            ->first();

            if ($client) {
                // Check for DNI / Name mismatch
                $dbNameNormalized = \mb_convert_case(mb_strtolower(trim($client->name), 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
                $excelNameNormalized = $data['name'];

                if (strcasecmp($dbNameNormalized, $excelNameNormalized) !== 0) {
                     $data['has_errors'] = true;
                     $error = "ERROR: La cédula $dni ya existe para este vendedor pero está registrada a nombre de: {$client->name}.";
                     if (!in_array($error, $data['warnings'])) {
                        $data['warnings'][] = $error;
                     }
                     $data['name'] = "(!) ERROR NOMBRE: " . $client->name . " (BD) / " . $excelNameNormalized . " (Archivo)";
                }
                // Check for phone uniqueness against DB (for existing client, must match their own phone or be unique)
                if (!empty($data['phone'])) {
                    $otherClientWithPhone = Client::where('phone', $data['phone'])
                        ->where('seller_id', $sellerId)
                        ->where('dni', '!=', $dni)
                        ->first();
                    if ($otherClientWithPhone) {
                        $error = "ERROR: El teléfono {$data['phone']} ya está registrado para otro cliente: {$otherClientWithPhone->name} (DNI: {$otherClientWithPhone->dni}).";
                        if (!in_array($error, $data['warnings'])) {
                            $data['warnings'][] = $error;
                        }
                        $data['needs_update'] = true;
                        $data['has_errors'] = true;
                    }
                }

                $summary['existing_clients']++;
                $data['is_new'] = false;
                $data['name'] = $client->name; // Use DB name if exists
                $data['active_credits'] = Credit::where('client_id', $client->id)->where('status', 'Vigente')->count();
                
                // Check if they need update based on current DB state OR missing data
                $hasAddress = !empty($client->address);
                $hasGPS = !empty($client->gps_address) || (!empty($client->geolocation['latitude']) && $client->geolocation['latitude'] != 0);
                $hasImages = $client->images()->count() > 0;
                $data['needs_update'] = $client->needs_update || !$hasAddress || !$hasGPS || !$hasImages;
            } else {
                $summary['new_clients']++;
                $data['needs_update'] = true; // NEW clients will need update

                // SOFT GLOBAL DNI CHECK: Inform if DNI exists in another seller with different name
                $globalClient = Client::where('dni', $dni)->first();
                if ($globalClient) {
                    $dbNameNormalized = \mb_convert_case(mb_strtolower(trim($globalClient->name), 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
                    $excelNameNormalized = $data['name'];
                    if (strcasecmp($dbNameNormalized, $excelNameNormalized) !== 0) {
                        $infoMsg = "INFO: Esta cédula ya está registrada en otra ruta a nombre de: {$globalClient->name}. Verifique si se trata de la misma persona.";
                        if (!in_array($infoMsg, $data['warnings'])) {
                            $data['warnings'][] = $infoMsg;
                        }
                    }
                }
                
                // Check for phone uniqueness for NEW clients
                if (!empty($data['phone'])) {
                    $otherClientWithPhone = Client::where('phone', $data['phone'])
                        ->where('seller_id', $sellerId)
                        ->first();
                    if ($otherClientWithPhone) {
                        $error = "ERROR: El teléfono {$data['phone']} ya está registrado para otro cliente: {$otherClientWithPhone->name} (DNI: {$otherClientWithPhone->dni}).";
                        if (!in_array($error, $data['warnings'])) {
                            $data['warnings'][] = $error;
                        }
                        $data['has_errors'] = true;
                    }
                }
            }
            
            if (!empty($data['warnings'])) {
                foreach($data['warnings'] as $w) {
                    if (str_starts_with($w, 'ERROR')) {
                        $summary['has_critical_errors'] = true;
                        break;
                    }
                }
            }

            $summary['client_details'][] = $data;
        }

        // We no longer count them as "duplicates" if they are multiple credits for the same client
        $summary['duplicate_entries_in_file'] = 0; 

        return $summary;
    }

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function importFromCsv($filePath, $sellerIdInput, $selectedDnis = null)
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
            'records' => [],
            'row' => 1
        ];

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $results['row']++;
            
            if (empty(array_filter($data))) continue;

            if (count($headers) !== count($data)) {
                $results['errors'][] = [
                    'line' => $results['row'],
                    'field' => 'Estructura',
                    'error' => "La fila tiene " . count($data) . " columnas, pero se esperaban " . count($headers) . ". Por favor revise que no haya comas extras."
                ];
                continue;
            }

            $row = array_combine($headers, $data);
            
            // Filter by selected DNIs if provided (even if empty)
            if (is_array($selectedDnis)) {
                $dni = trim($row['cliente_dni'] ?? '');
                if (!in_array($dni, $selectedDnis)) {
                    continue; // Skip if not selected
                }
            }

            $this->validateAndProcess($row, $seller, $results);
        }

        fclose($handle);
        return $results;
    }

    protected function validateAndProcess(&$row, $seller, &$results)
    {
        // Robust date parsing for fecha_entrega
        if (isset($row['fecha_entrega']) && !empty($row['fecha_entrega'])) {
            $val = $row['fecha_entrega'];
            // If it's a numeric Excel date (e.g. 45346)
            if (is_numeric($val) && $val > 30000) { 
                try {
                    $dateTime = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($val);
                    $row['fecha_entrega'] = $dateTime->format('Y-m-d');
                } catch (\Exception $e) {
                    // Fallback or leave as is for validator to catch
                }
            } else if (is_string($val)) {
                // Try to parse common formats if it doesn't match Y-m-d
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                    try {
                        // Handle d/m/Y or d-m-Y
                        $cleaned = str_replace('/', '-', $val);
                        $carbonDate = \Carbon\Carbon::parse($cleaned);
                        $row['fecha_entrega'] = $carbonDate->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Leave as is
                    }
                }
            }
        }

        // Robust date parsing for fecha_primera_cuota
        if (isset($row['fecha_primera_cuota']) && !empty($row['fecha_primera_cuota'])) {
            $val = $row['fecha_primera_cuota'];
            if (is_numeric($val) && $val > 30000) {
                try {
                    $dateTime = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($val);
                    $row['fecha_primera_cuota'] = $dateTime->format('Y-m-d');
                } catch (\Exception $e) {}
            } else if (is_string($val)) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                    try {
                        $cleaned = str_replace('/', '-', $val);
                        $carbonDate = \Carbon\Carbon::parse($cleaned);
                        $row['fecha_primera_cuota'] = $carbonDate->format('Y-m-d');
                    } catch (\Exception $e) {}
                }
            }
        }

        $friendlyNames = [
            'cliente_nombre' => 'Nombre del Cliente',
            'cliente_dni' => 'DNI/Cédula',
            'cliente_telefono' => 'Teléfono',
            'monto_credito' => 'Monto del Crédito',
            'tasa_interes' => 'Tasa de Interés',
            'cuotas_numero' => 'Número de Cuotas',
            'fecha_entrega' => 'Fecha de Entrega',
            'frecuencia' => 'Frecuencia de Pago',
            'fecha_primera_cuota' => 'Fecha de Primera Cuota',
            'microseguro_porcentaje' => 'Microseguro (%)',
            'pagos_realizados' => 'Pagos Realizados',
            'excluir_domingos' => 'Excluir Domingos'
        ];

        $validator = \Illuminate\Support\Facades\Validator::make($row, [
            'cliente_nombre' => 'required|string|max:255',
            'cliente_dni' => 'required',
            'cliente_telefono' => 'required',
            'monto_credito' => 'required|numeric|min:0',
            'tasa_interes' => 'required|numeric|min:0',
            'cuotas_numero' => 'required|integer|min:1',
            'fecha_entrega' => 'required|date_format:Y-m-d',
            'frecuencia' => 'required|in:Diaria,Semanal,Quincenal,Mensual',
            'fecha_primera_cuota' => 'required', // Validation handled manually for formulas
            'microseguro_porcentaje' => 'required|numeric|min:0',
            'pagos_realizados' => 'required|numeric|min:0',
            'excluir_domingos' => 'required|in:SI,NO'
        ], [
            'required' => 'El campo :attribute es obligatorio.',
            'numeric' => 'El campo :attribute debe ser un número válido.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'in' => 'La :attribute seleccionada no es válida.',
            'min' => 'El valor de :attribute no puede ser menor a :min.'
        ], $friendlyNames);

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $results['errors'][] = [
                    'line' => $results['row'],
                    'field' => $friendlyNames[$field] ?? $field,
                    'error' => $messages[0]
                ];
            }
            return false;
        }

        DB::beginTransaction();
        try {
            $processed = $this->processRow($row, $seller);
            $credit = $processed['credit'];
            $warnings = $processed['warnings'] ?? [];
            
            DB::commit();
            $results['success']++;
            $results['records'][] = [
                'name' => $row['cliente_nombre'],
                'dni' => $row['cliente_dni'],
                'amount' => $row['monto_credito'],
                'credit_id' => $credit->id,
                'is_new' => $processed['is_new_client'],
                'needs_update' => $processed['needs_update'],
                'warnings' => $warnings
            ];
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            $errorMessage = $this->translateToFriendlyError($e->getMessage(), $row);
            $results['errors'][] = [
                'line' => $results['row'],
                'field' => 'Procesamiento',
                'error' => $errorMessage
            ];
            Log::error("Error importando fila {$results['row']}: " . $e->getMessage());
            return false;
        }
    }

    protected function translateToFriendlyError($technicalError, $row)
    {
        if (str_contains($technicalError, 'Incorrect date value')) {
            if (str_contains($technicalError, 'first_quota_date')) {
                return "La 'Fecha de Primera Cuota' contiene un formato no válido o una fórmula sin calcular. Por favor, asegúrese de ingresar una fecha real (ej: 2025-01-30).";
            }
            return "Una de las fechas ingresadas no tiene el formato correcto (YYYY-MM-DD).";
        }

        if (str_contains($technicalError, 'Duplicate entry')) {
            return "Ya existe un registro con el DNI {$row['cliente_dni']} para este vendedor.";
        }
        
        if (str_starts_with($technicalError, 'DNI_MISMATCH|')) {
            return explode('|', $technicalError)[1];
        }

        if (str_contains($technicalError, 'Integrity constraint violation')) {
            return "No se pudo guardar la información debido a una restricción de datos. Verifique que los valores sean correctos.";
        }

        return "Hubo un problema al procesar esta fila. Verifique que los montos y fechas tengan el formato correcto.";
    }

    public function importFromExcel($filePath, $sellerIdInput, $selectedDnis = null)
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
            'records' => [],
            'row' => 1
        ];

        foreach ($rows as $data) {
            $results['row']++;
            
            if (empty(array_filter($data))) continue;

            if (count($headers) !== count($data)) {
                 $results['errors'][] = [
                    'line' => $results['row'],
                    'field' => 'Estructura',
                    'error' => "Columnas desiguales en la fila."
                ];
                continue;
            }

            $row = array_combine($headers, $data);

            // Filter by selected DNIs if provided (even if empty)
            if (is_array($selectedDnis)) {
                $dni = trim($row['cliente_dni'] ?? '');
                if (!in_array($dni, $selectedDnis)) {
                    continue; // Skip if not selected
                }
            }

            $this->validateAndProcess($row, $seller, $results);
        }

        return $results;
    }

    public function downloadExcelTemplate($sellerId = null)
    {
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', '300');
        
        Log::info("ImportService: Starting template generation for seller: " . ($sellerId ?? 'General'));
        
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
        
        // Auto-size columns (skip I to avoid calculation overhead/crashes on empty rows)
        foreach (range('A', 'L') as $col) {
            if ($col == 'I') {
                $sheet->getColumnDimension($col)->setWidth(20);
                continue;
            }
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        Log::info("ImportService: Applying validations and formulas to 1000 rows");
        // Apply validations and formula to rows 2 through 1000
        for ($i = 2; $i <= 1000; $i++) {
            $sheet->getCell('H' . $i)->setDataValidation(clone $frequencyValidation);
            $sheet->getCell('L' . $i)->setDataValidation(clone $sundayValidation);
            
            // Formula for fecha_primera_cuota (column I)
            // Wrap it in an IF(G$i="", "", ...) to avoid errors on empty rows
            $formula = '=IF(G'.$i.'="", "", IF(L'.$i.'="SI",IF(WEEKDAY(IF(H'.$i.'="Diaria",G'.$i.'+1,IF(H'.$i.'="Semanal",G'.$i.'+7,IF(H'.$i.'="Quincenal",G'.$i.'+15,G'.$i.'+30))))=1,IF(H'.$i.'="Diaria",G'.$i.'+2,IF(H'.$i.'="Semanal",G'.$i.'+8,IF(H'.$i.'="Quincenal",G'.$i.'+16,G'.$i.'+31))),IF(H'.$i.'="Diaria",G'.$i.'+1,IF(H'.$i.'="Semanal",G'.$i.'+7,IF(H'.$i.'="Quincenal",G'.$i.'+15,G'.$i.'+30)))),IF(H'.$i.'="Diaria",G'.$i.'+1,IF(H'.$i.'="Semanal",G'.$i.'+7,IF(H'.$i.'="Quincenal",G'.$i.'+15,G'.$i.'+30)))))';
            $sheet->setCellValue('I' . $i, $formula);
        }
        
        // Format date columns for rows 2 through 1000
        $sheet->getStyle('G2:G1000')->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_YYYYMMDD2);
        $sheet->getStyle('I2:I1000')->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_YYYYMMDD2);
        
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
            'IMPORTANTE:',
            '- TODOS LOS CAMPOS SON OBLIGATORIOS.',
            '- La fecha_primera_cuota se calcula automáticamente basándose en fecha_entrega + frecuencia.',
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
        $writer->setPreCalculateFormulas(false);
        
        $tempDir = storage_path('app/public');
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $temp_file = $tempDir . DIRECTORY_SEPARATOR . 'template_' . time() . '.xlsx';
        
        Log::info("ImportService: Saving file to: " . $temp_file);
        $writer->save($temp_file);
        Log::info("ImportService: File saved successfully");
        
        if (ob_get_length()) ob_end_clean();
        
        return response()->download($temp_file, $filename . '.xlsx')->deleteFileAfterSend(true);
    }

    protected function processRow($row, $seller)
    {
        Log::info("ImportService: Processing row", ['dni' => $row['cliente_dni'] ?? 'N/A']);
        $sellerId = $seller->id;

        // 1. Find or Create Client
        $warnings = [];
        // Force Title Case on the incoming name
        $row['cliente_nombre'] = \mb_convert_case(mb_strtolower(trim($row['cliente_nombre']), 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        
        $client = Client::where('dni', $row['cliente_dni'])
                        ->where('seller_id', $sellerId)
                        ->first();

        if ($client) {
            // Check for existing credits
            $activeCredits = Credit::where('client_id', $client->id)
                ->where('status', 'Vigente')
                ->get();
            
            if ($activeCredits->count() > 0) {
                $warnings[] = "El cliente ya tiene " . $activeCredits->count() . " crédito(s) vigente(s).";
            }
        }

        if (!$client) {
            // Check if phone already exists for THIS seller
            if (!empty($row['cliente_telefono'])) {
                $duplicatePhone = Client::where('phone', $row['cliente_telefono'])
                    ->where('seller_id', $sellerId)
                    ->first();
                if ($duplicatePhone) {
                    throw new \Exception("PHONE_DUPLICATE|El teléfono {$row['cliente_telefono']} ya está asociado a otro cliente: {$duplicatePhone->name} (DNI: {$duplicatePhone->dni}).");
                }
            }

            // Client does not exist, create it
            $client = Client::create([
                'uuid' => (string) Str::uuid(),
                'name' => $row['cliente_nombre'],
                'dni' => $row['cliente_dni'],
                'phone' => $row['cliente_telefono'],
                'seller_id' => $sellerId,
                'status' => 'active',
                'address' => '',
                'geolocation' => ['latitude' => 0, 'longitude' => 0],
                'routing_order' => Client::where('seller_id', $sellerId)->max('routing_order') + 1,
            ]);
        } else {
            // Update phone if different and not taken
            if (!empty($row['cliente_telefono']) && $client->phone !== $row['cliente_telefono']) {
                $duplicatePhone = Client::where('phone', $row['cliente_telefono'])
                    ->where('seller_id', $sellerId)
                    ->where('dni', '!=', $client->dni)
                    ->first();
                if ($duplicatePhone) {
                    throw new \Exception("PHONE_DUPLICATE|El teléfono {$row['cliente_telefono']} ya está asociado a otro cliente: {$duplicatePhone->name} (DNI: {$duplicatePhone->dni}).");
                }
                $client->update(['phone' => $row['cliente_telefono']]);
            }

            // If client exists, check if they are missing mandatory data
            $hasAddress = !empty($client->address);
            $hasGPS = !empty($client->gps_address) || (!empty($client->geolocation['latitude']) && $client->geolocation['latitude'] != 0);
            $hasImages = $client->images()->count() > 0;

            if (!$hasAddress || !$hasGPS || !$hasImages) {
                // $client->update(['needs_update' => true]); // Column missing in DB
            }
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
        
        // If it starts with "=", it's an uncalculated formula from Excel
        if (is_string($firstQuotaDate) && str_starts_with($firstQuotaDate, '=')) {
            Log::warning("ImportService: Formula detected in fecha_primera_cuota, ignoring to let system calculate", ['formula' => $firstQuotaDate]);
            $firstQuotaDate = null;
        }

        // Validate basic date format if present
        if (!empty($firstQuotaDate)) {
            try {
                Carbon::parse($firstQuotaDate);
            } catch (\Exception $e) {
                Log::warning("ImportService: Invalid date string in fecha_primera_cuota, ignoring", ['value' => $firstQuotaDate]);
                $firstQuotaDate = null; // Let the system calculate it if invalid
            }
        }

        $excludeSundays = ($row['excluir_domingos'] ?? 'SI') === 'SI';

        if (empty($firstQuotaDate)) {
            $rawPayoutDate = $row['fecha_entrega'] ?? null;
            // Handle possible formula in payout date too
            if (is_string($rawPayoutDate) && str_starts_with($rawPayoutDate, '=')) {
                $rawPayoutDate = null;
            }
            
            $date = Carbon::parse($rawPayoutDate ?? now());
            $date->addDay();
            
            // Skip Sundays if requested
            if ($excludeSundays) {
                while ($date->dayOfWeek === Carbon::SUNDAY) {
                    $date->addDay();
                }
            }
            $firstQuotaDate = $date->format('Y-m-d');
        }

        $payoutDate = $row['fecha_entrega'] ?? Carbon::now()->format('Y-m-d');
        if (is_string($payoutDate) && str_starts_with($payoutDate, '=')) {
            $payoutDate = Carbon::now()->format('Y-m-d');
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
            'is_initial_credit' => true,
        ]);

        // 3. Generate Installments
        Log::info("ImportService: Generating installments for credit", ['credit_id' => $credit->id]);
        $this->generateInstallments($credit);

        // 4. Handle Historical Payments
        $paidAmount = floatval($row['pagos_realizados'] ?? 0);
        Log::info("ImportService: Handling historical payments", ['paid_amount' => $paidAmount]);
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

        return [
            'credit' => $credit,
            'warnings' => $warnings,
            'is_new_client' => !isset($existingClientId),
            'needs_update' => $client->needs_update
        ];
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
