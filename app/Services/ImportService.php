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

    public function downloadExcelTemplate()
    {
        $headers = [
            'cliente_nombre', 'cliente_dni', 'cliente_telefono', 'monto_credito',
            'tasa_interes', 'cuotas_numero', 'frecuencia', 'fecha_primera_cuota',
            'fecha_entrega', 'poliza_monto', 'pagos_realizados', 'excluir_domingos'
        ];
        
        $exampleRow = [
            'Juan Pérez', '12345678', '0987654321', 1000, 20, 24, 'Diaria', 
            Carbon::now()->addDay()->format('Y-m-d'), 
            Carbon::now()->format('Y-m-d'), 0, 0, 'SI'
        ];

        return Excel::download(new class($headers, $exampleRow) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings {
            protected $headers;
            protected $row;
            public function __construct($headers, $row) { $this->headers = $headers; $this->row = $row; }
            public function collection() { return collect([$this->row]); }
            public function headings(): array { return $this->headers; }
        }, 'plantilla_importacion.xlsx');
    }

    protected function processRow($row, $seller)
    {
        $sellerId = $seller->id;

        // 1. Create Client
        $client = Client::where('dni', $row['cliente_dni'])
                        ->where('seller_id', $sellerId)
                        ->first();

        if ($client) {
            // Check if we should ignore or update. For migration, usually we expect clean data.
            throw new \Exception("El cliente con DNI {$row['cliente_dni']} ya existe para este vendedor.");
        }

        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'name' => $row['cliente_nombre'],
            'dni' => $row['cliente_dni'],
            'phone' => $row['cliente_telefono'] ?? '0',
            'seller_id' => $sellerId,
            'status' => 'active',
            'needs_update' => true, // Mark for update (Address, GPS, Photos missing)
            'address' => '',
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
            'routing_order' => Client::where('seller_id', $sellerId)->max('routing_order') + 1,
        ]);

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
        $microInsuranceAmount = floatval($row['poliza_monto'] ?? 0);

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
