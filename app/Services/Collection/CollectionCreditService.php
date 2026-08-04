<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCapitalAddition;
use App\Models\Collection\CollectionClient;
use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionInstallment;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CollectionCreditService
{
    use ApiResponse;

    private const CONNECTION = 'collection_pgsql';

    public function __construct(
        private readonly CollectionPartitionService $partitionService,
        private readonly CollectionCashClosureService $closureSvc,
    ) {
    }

    public function create(array $payload)
    {
        $companyId = $this->resolveCompanyId($payload['company_id'] ?? null);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $clientId = (int) ($payload['client_id'] ?? 0);
        if ($clientId <= 0) {
            return $this->errorResponse('Cliente inválido para Collection', 422);
        }

        $clientExists = CollectionClient::query()
            ->where('company_id', $companyId)
            ->where('id', $clientId)
            ->exists();

        if (!$clientExists) {
            return $this->errorNotFoundResponse('Cliente Collection no encontrado');
        }

        $this->partitionService->ensurePartitions($companyId);
        
        return DB::connection(self::CONNECTION)->transaction(function () use ($payload, $companyId, $clientId) {
            // id generado por la secuencia de PostgreSQL.
            $creditValue = (float) ($payload['credit_value'] ?? 0);
            $interestRate = (float) ($payload['interest_rate'] ?? 0);

            // Unico modo soportado: credito abierto con interes mensual.
            // Se genera 1 cuota inicial; las siguientes se crean al pagar.
            $installments = 1;
            $frequency = 'Mensual';

            $firstInstallmentDate = $payload['first_installment_date'] ?? null;
            if (!$firstInstallmentDate) {
                $firstInstallmentDate = Carbon::now()->toDateString();
            }

            $metadata = [
                'transfer_bank_name' => $payload['transfer_bank_name'] ?? null,
                'transfer_reference_number' => $payload['transfer_reference_number'] ?? null,
                'transfer_voucher_photo' => $payload['transfer_voucher_photo'] ?? null,
                'transfer_support_photo' => $payload['transfer_support_photo'] ?? null,
                'excluded_days' => $payload['excluded_days'] ?? [],
                'installment_distribution_mode' => 'monthly_interest_open',
                'is_open_ended' => true,
                'created_by' => Auth::id(),
                'created_ip' => request()->ip(),
            ];

            // Par moneda/pais: define la caja donde cae el credito. Si el payload
            // no lo trae, se cae al DEFAULT DE LA EMPRESA en vez de a un COP/CO
            // hardcodeado: una empresa que solo opera en Peru no tiene por que
            // heredar Colombia. Que el par este habilitado se valida mas abajo.
            $config = \App\Models\Collection\CollectionCompanyConfig::where('company_id', $companyId)->first();
            $currency = strtoupper($payload['currency'] ?? '') ?: (($config->default_currency ?? null) ?: 'COP');
            $countryCode = strtoupper($payload['country_code'] ?? '') ?: (($config->default_country_code ?? null) ?: 'CO');

            // Fecha contable del crédito (desembolso): usa la fecha seleccionada
            // por el usuario (credit_date) o hoy por defecto. Se ancla al día
            // calendario en la zona horaria del país del crédito y se rechaza si
            // es futura (defensa server-side; la UI ya lo impide).
            $creditTz = \App\Helpers\TimezoneHelper::timezoneForCountryCode($countryCode) ?: 'America/Bogota';
            $todayInTz = Carbon::now($creditTz)->toDateString();
            $businessDate = !empty($payload['credit_date'])
                ? Carbon::parse($payload['credit_date'])->toDateString()
                : $todayInTz;
            if ($businessDate > $todayInTz) {
                return $this->errorResponse('La fecha de crédito no puede ser futura', 422);
            }

            // Validar contra la configuración de la empresa (ya cargada arriba
            // para resolver el par por defecto).
            if ($config) {
                if (!$config->hasCurrency($currency, $countryCode)) {
                    return $this->errorResponse("La moneda {$currency} ({$countryCode}) no está habilitada para esta empresa.", 422);
                }
            } else if ($currency !== 'COP' || $countryCode !== 'CO') {
                // Si no hay config, solo permitimos COP/CO por defecto
                return $this->errorResponse("Esta empresa no tiene configuradas monedas adicionales.", 422);
            }

            $createData = [
                'company_id' => $companyId,
                'client_id' => $clientId,
                'amount' => $creditValue,
                'interest_rate' => $interestRate,
                'total_installments' => $installments,
                'payment_frequency' => $frequency,
                'first_installment_date' => $firstInstallmentDate,
                'status' => 'active',
                'business_date' => $businessDate,
                'metadata' => $metadata,
                'currency' => $currency,
                'country_code' => $countryCode,
            ];

            // Nombre de la ruta y descripción: se escriben solo si la columna ya
            // existe (la migración puede correr en una ventana separada). Antes de
            // migrar, degradan a no-op sin romper la creación del crédito.
            if ($this->hasCreditColumn('route_name')) {
                $createData['route_name'] = trim((string) ($payload['route_name'] ?? '')) ?: null;
            }
            if ($this->hasCreditColumn('description')) {
                $createData['description'] = trim((string) ($payload['description'] ?? '')) ?: null;
            }

            $credit = CollectionCredit::query()->create($createData);

            // Sync with Centralized Wallet (Outflow: Issuing a loan)
            app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
                'company_id' => $companyId,
                'currency' => $currency,
                'country_code' => $countryCode,
                'amount' => $creditValue,
                'type' => 'debit',
                'action_type' => 'loan_issue',
                'reference_type' => 'credit',
                'reference_id' => $credit->id,
                'description' => "Colocación de crédito #{$credit->id}",
            ]);

            $this->generateInstallments($credit, $payload['excluded_days'] ?? []);

            return $this->successCreatedResponse([
                'success' => true,
                'message' => 'Crédito Collection creado',
                'data' => [
                    'id' => $credit->id,
                    'client_id' => $credit->client_id,
                ],
            ]);
        });
    }

    /**
     * Ventana de edición de un crédito: SOLO el mismo día contable en que se
     * colocó, y siempre que nada haya pasado encima todavía.
     *
     * Un crédito no es un registro suelto: al crearse debita la wallet
     * (loan_issue) y genera la cuota vigente. Por eso la corrección se permite
     * únicamente mientras el día siga "vivo":
     *  - status = 'active'
     *  - su día contable (business_date) es HOY en la zona del país del crédito
     *  - la caja de ese día no está cerrada (el corte es definitivo)
     *  - sin abonos aplicados y sin adiciones de capital, si se toca el dinero
     *
     * La ventana se mide por el DÍA REAL DE REGISTRO (created_at corregido), no
     * por `business_date`: un crédito cargado hoy debe poder corregirse hoy
     * aunque se le haya puesto una fecha de desembolso anterior. Medirlo por
     * business_date cerraba la ventana en el acto al retrofechar.
     *
     * La caja que se valida sí es la del día contable: es la que va a contar
     * ese movimiento en el corte.
     *
     * Devuelve [bool $editable, ?string $motivo]. El motivo se muestra en la UI
     * para que el usuario entienda por qué el botón está deshabilitado.
     */
    public function creditEditability(CollectionCredit $credit): array
    {
        if ($credit->status !== 'active') {
            return [false, 'Solo se pueden editar créditos activos.'];
        }

        $tz = \App\Helpers\TimezoneHelper::timezoneForCountryCode($credit->country_code) ?: 'America/Bogota';
        $today = Carbon::now($tz)->toDateString();

        $registeredOn = $this->localRegistrationDate($credit, $tz)
            ?: optional($credit->business_date)->toDateString();

        if ($registeredOn !== $today) {
            return [false, 'Un crédito solo puede editarse el mismo día en que se registró.'];
        }

        $businessDate = optional($credit->business_date)->toDateString() ?: $today;
        if ($this->closureSvc->isDayClosed($credit->company_id, $businessDate)) {
            return [false, 'La caja del día ' . $businessDate . ' está cerrada: el corte ya es definitivo.'];
        }

        return [true, null];
    }

    /**
     * Día local en que se registró el crédito, listo para mostrar en la UI.
     *
     * Es distinto de `business_date`: uno es CUÁNDO se cargó y el otro A QUÉ DÍA
     * se imputa. Coinciden salvo que se haya retrofechado el desembolso.
     */
    public function creditRegistrationDate(CollectionCredit $credit): ?string
    {
        $tz = \App\Helpers\TimezoneHelper::timezoneForCountryCode($credit->country_code) ?: 'America/Bogota';

        return $this->localRegistrationDate($credit, $tz);
    }

    /**
     * Día local en que se registró de verdad la fila, corrigiendo el desfase de
     * la conexión Postgres.
     *
     * El problema: `created_at` acá es `timestamp with time zone`, la app corre
     * en UTC y Postgres en America/Caracas. Laravel manda la hora UTC como
     * texto SIN offset y Postgres la interpreta en su zona (-04), así que el
     * instante guardado queda 4 horas adelantado. Sin corregirlo, todo lo
     * creado después de las 20:00 locales parece de mañana y la ventana del
     * mismo día se cierra sola.
     *
     * La corrección usa el offset real de la conexión, así que si algún día se
     * arregla la config (`'timezone' => 'UTC'`) el offset pasa a 0 y este
     * ajuste se vuelve un no-op automáticamente.
     */
    private function localRegistrationDate($model, string $tz): ?string
    {
        if (!$model->created_at) {
            return null;
        }

        return $model->created_at
            ->copy()
            ->addSeconds($this->pgTimezoneOffsetSeconds())
            ->setTimezone($tz)
            ->toDateString();
    }

    /** Offset en segundos de la zona horaria de la conexión Postgres (-14400 para -04). */
    private function pgTimezoneOffsetSeconds(): int
    {
        static $cached = null;

        if ($cached === null) {
            try {
                $row = DB::connection(self::CONNECTION)
                    ->select('SELECT EXTRACT(TIMEZONE FROM now()) AS offset_seconds');
                $cached = (int) ($row[0]->offset_seconds ?? 0);
            } catch (\Throwable $e) {
                $cached = 0;
            }
        }

        return $cached;
    }

    /**
     * Edita un crédito dentro de la ventana del mismo día.
     *
     * Los campos no financieros (ruta, descripción, banco/referencia) se pueden
     * corregir siempre que la ventana esté abierta. Monto, tasa y fecha de
     * primera cuota además exigen que el crédito esté intacto: sin abonos y sin
     * adiciones de capital, porque recalcular sobre pagos ya aplicados
     * corrompería el histórico.
     */
    public function update(int $creditId, array $payload)
    {
        $companyId = $this->resolveCompanyId($payload['company_id'] ?? null);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $credit = CollectionCredit::query()
            ->where('id', $creditId)
            ->where('company_id', $companyId)
            ->first();

        if (!$credit) {
            return $this->errorNotFoundResponse('Crédito Collection no encontrado');
        }

        [$editable, $reason] = $this->creditEditability($credit);
        if (!$editable) {
            return $this->errorResponse($reason, 409);
        }

        $meta = is_array($credit->metadata) ? $credit->metadata : [];
        $mode = $meta['installment_distribution_mode'] ?? 'monthly_interest_open';

        // Valores entrantes: lo que no venga en el payload conserva su valor.
        $oldAmount = (float) $credit->amount;
        $oldRate = (float) $credit->interest_rate;
        $oldFirstDate = optional($credit->first_installment_date)->toDateString();

        $newAmount = array_key_exists('credit_value', $payload) && $payload['credit_value'] !== null
            ? (float) $payload['credit_value']
            : $oldAmount;
        $newRate = array_key_exists('interest_rate', $payload) && $payload['interest_rate'] !== null
            ? (float) $payload['interest_rate']
            : $oldRate;
        $newFirstDate = !empty($payload['first_installment_date'])
            ? Carbon::parse($payload['first_installment_date'])->toDateString()
            : $oldFirstDate;

        // Fecha contable del crédito (desembolso). Puede corregirse hacia atrás
        // igual que al crearlo, pero nunca a futuro ni a un día ya cortado.
        $tz = \App\Helpers\TimezoneHelper::timezoneForCountryCode($credit->country_code) ?: 'America/Bogota';
        $todayInTz = Carbon::now($tz)->toDateString();
        $oldBusinessDate = optional($credit->business_date)->toDateString() ?: $todayInTz;
        $newBusinessDate = !empty($payload['credit_date'])
            ? Carbon::parse($payload['credit_date'])->toDateString()
            : $oldBusinessDate;

        if ($newBusinessDate !== $oldBusinessDate) {
            if ($newBusinessDate > $todayInTz) {
                return $this->errorResponse('La fecha del crédito no puede ser futura', 422);
            }
            if ($this->closureSvc->isDayClosed($companyId, $newBusinessDate)) {
                return $this->errorResponse(
                    'No se puede mover el crédito al ' . $newBusinessDate . ': la caja de ese día está cerrada.',
                    409
                );
            }
        }

        if ($newAmount <= 0) {
            return $this->errorResponse('El monto del crédito debe ser mayor a 0', 422);
        }
        if ($newRate < 0) {
            return $this->errorResponse('La tasa de interés no puede ser negativa', 422);
        }

        $financialChanged = abs($newAmount - $oldAmount) >= 0.01
            || abs($newRate - $oldRate) >= 0.0001
            || $newFirstDate !== $oldFirstDate;

        if ($financialChanged) {
            if ($mode !== 'monthly_interest_open') {
                return $this->errorResponse(
                    'Este crédito usa un esquema de cuotas antiguo; sus montos no se pueden editar.',
                    422
                );
            }

            $paid = (float) CollectionInstallment::query()
                ->where('company_id', $companyId)
                ->where('credit_id', $credit->id)
                ->whereNull('deleted_at')
                ->sum('paid_amount');

            if ($paid > 0) {
                return $this->errorResponse(
                    'El crédito ya tiene abonos registrados: no se pueden cambiar monto, tasa ni fecha. Reversá los abonos primero.',
                    409
                );
            }

            $hasAdditions = CollectionCapitalAddition::query()
                ->where('company_id', $companyId)
                ->where('credit_id', $credit->id)
                ->exists();

            if ($hasAdditions) {
                return $this->errorResponse(
                    'El crédito ya tiene adiciones de capital: no se pueden cambiar monto, tasa ni fecha.',
                    409
                );
            }
        }

        return DB::connection(self::CONNECTION)->transaction(function () use (
            $credit, $companyId, $payload, $meta,
            $oldAmount, $oldRate, $oldFirstDate, $oldBusinessDate,
            $newAmount, $newRate, $newFirstDate, $newBusinessDate, $financialChanged
        ) {
            $oldSnapshot = [
                'amount' => $oldAmount,
                'interest_rate' => $oldRate,
                'first_installment_date' => $oldFirstDate,
                'business_date' => $oldBusinessDate,
                'route_name' => $credit->route_name ?? null,
                'description' => $credit->description ?? null,
                'transfer_bank_name' => $meta['transfer_bank_name'] ?? null,
                'transfer_reference_number' => $meta['transfer_reference_number'] ?? null,
                'transfer_voucher_photo' => $meta['transfer_voucher_photo'] ?? null,
                'transfer_support_photo' => $meta['transfer_support_photo'] ?? null,
            ];

            $credit->amount = $newAmount;
            $credit->interest_rate = $newRate;
            $credit->first_installment_date = $newFirstDate;
            $credit->business_date = $newBusinessDate;

            if ($this->hasCreditColumn('route_name') && array_key_exists('route_name', $payload)) {
                $credit->route_name = trim((string) $payload['route_name']) ?: null;
            }
            if ($this->hasCreditColumn('description') && array_key_exists('description', $payload)) {
                $credit->description = trim((string) $payload['description']) ?: null;
            }

            if (array_key_exists('transfer_bank_name', $payload)) {
                $meta['transfer_bank_name'] = $payload['transfer_bank_name'] ?: null;
            }
            if (array_key_exists('transfer_reference_number', $payload)) {
                $meta['transfer_reference_number'] = $payload['transfer_reference_number'] ?: null;
            }

            // Imágenes: solo se reemplazan si vino un archivo nuevo (el controller
            // ya lo guardó y pasa la ruta). El archivo anterior NO se borra, para
            // que la trazabilidad pueda seguir mostrándolo.
            foreach (['transfer_voucher_photo', 'transfer_support_photo'] as $photoField) {
                if (!empty($payload[$photoField])) {
                    $meta[$photoField] = $payload[$photoField];
                }
            }
            $meta['last_edited_by'] = Auth::id();
            $meta['last_edited_at'] = Carbon::now()->toISOString();
            $credit->metadata = $meta;
            $credit->save();

            if ($financialChanged) {
                // Modo abierto: existe UNA cuota vigente de solo interés y está
                // intacta (lo garantizan los guards). Se corrige en sitio en vez
                // de borrar y regenerar, para no dejar filas fantasma.
                $interest = round(($newAmount * $newRate) / 100, 2);
                CollectionInstallment::query()
                    ->where('company_id', $companyId)
                    ->where('credit_id', $credit->id)
                    ->whereNull('deleted_at')
                    ->update([
                        'amount' => $interest,
                        'interest_amount' => $interest,
                        'principal_amount' => 0,
                        'due_date' => $newFirstDate,
                    ]);

                // La wallet tiene saldo persistido: se ajusta por el delta con un
                // movimiento nuevo (append-only), nunca reescribiendo el original.
                //   delta > 0 → se colocó MÁS  → débito adicional
                //   delta < 0 → se colocó MENOS → reintegro a caja
                $delta = round($newAmount - $oldAmount, 2);
                if (abs($delta) >= 0.01) {
                    app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
                        'company_id' => $companyId,
                        'currency' => strtoupper($credit->currency ?: 'COP'),
                        'country_code' => strtoupper($credit->country_code ?: 'CO'),
                        'amount' => abs($delta),
                        'type' => $delta > 0 ? 'debit' : 'credit',
                        'action_type' => 'loan_issue_adjustment',
                        'reference_type' => 'credit',
                        'reference_id' => $credit->id,
                        'description' => "Ajuste por edición de crédito #{$credit->id}: "
                            . number_format($oldAmount, 2) . ' → ' . number_format($newAmount, 2),
                    ]);
                }
            }

            \App\Models\Collection\CollectionCreditAudit::query()->create([
                'company_id' => $companyId,
                'credit_id' => $credit->id,
                'action' => 'updated',
                'user_id' => Auth::id(),
                'ip_address' => request()->ip(),
                'changes' => [
                    'old' => $oldSnapshot,
                    'new' => [
                        'amount' => (float) $credit->amount,
                        'interest_rate' => (float) $credit->interest_rate,
                        'first_installment_date' => optional($credit->first_installment_date)->toDateString(),
                        'business_date' => optional($credit->business_date)->toDateString(),
                        'route_name' => $credit->route_name ?? null,
                        'description' => $credit->description ?? null,
                        'transfer_bank_name' => $meta['transfer_bank_name'] ?? null,
                        'transfer_reference_number' => $meta['transfer_reference_number'] ?? null,
                        'transfer_voucher_photo' => $meta['transfer_voucher_photo'] ?? null,
                        'transfer_support_photo' => $meta['transfer_support_photo'] ?? null,
                    ],
                ],
            ]);

            return $this->successResponse([
                'success' => true,
                'message' => 'Crédito actualizado',
                'data' => [
                    'id' => $credit->id,
                    'amount' => (float) $credit->amount,
                    'interest_rate' => (float) $credit->interest_rate,
                ],
            ]);
        });
    }

    /**
     * Datos del "cartón" digital del crédito: la vista que reemplaza al cartón
     * de papel del cobrador.
     *
     * Se arma acá y no en el frontend a propósito: el mismo payload alimenta la
     * pantalla, la imagen que se comparte por WhatsApp y el PDF. Si cada canal
     * calculara sus totales, tarde o temprano mostrarían números distintos del
     * mismo crédito, que es justo lo que un cartón no puede permitirse.
     *
     * Ojo con el desembolso: `credits.amount` YA incluye las adiciones de
     * capital (así lo escribe addCapital), así que el desembolso original se
     * reconstruye restándolas.
     */
    public function cardboard(int $creditId, ?int $requestedCompanyId = null)
    {
        $companyId = $this->resolveCompanyId($requestedCompanyId);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $credit = CollectionCredit::query()
            ->where('id', $creditId)
            ->where('company_id', $companyId)
            ->first();

        if (!$credit) {
            return $this->errorNotFoundResponse('Crédito Collection no encontrado');
        }

        return $this->successResponse([
            'success' => true,
            'data' => $this->buildCardboardData($credit, $companyId),
        ]);
    }

    /**
     * Mismo cartón, en PDF. Usa exactamente el mismo payload que la pantalla
     * para que no puedan mostrar cifras distintas.
     */
    public function cardboardPdf(int $creditId, ?int $requestedCompanyId = null)
    {
        $companyId = $this->resolveCompanyId($requestedCompanyId);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $credit = CollectionCredit::query()
            ->where('id', $creditId)
            ->where('company_id', $companyId)
            ->first();

        if (!$credit) {
            return $this->errorNotFoundResponse('Crédito Collection no encontrado');
        }

        $data = $this->buildCardboardData($credit, $companyId);
        $currency = $data['credit']['currency'] ?: 'COP';

        $data['money'] = fn ($value) => $currency . ' ' . number_format((float) $value, 2, ',', '.');
        $data['fecha'] = function ($value) {
            if (!$value) {
                return '—';
            }
            return Carbon::parse($value)->format('d/m/Y');
        };

        $pdf = app('dompdf.wrapper');
        $pdf->loadView('collection.credit-cardboard', $data);
        $pdf->setPaper('a4', 'portrait');

        $safe = fn ($s) => preg_replace('/[^A-Za-z0-9_\-]/', '_', trim((string) $s));
        $fileName = 'carton_credito_' . $credit->id
            . '_' . $safe($data['client']['name'] ?? 'cliente') . '.pdf';

        return response()->make($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    /** Arma el payload del cartón. Reutilizado por el endpoint JSON y por el PDF. */
    public function buildCardboardData(CollectionCredit $credit, int $companyId): array
    {
        $client = CollectionClient::find($credit->client_id);
        $clientMeta = is_array($client?->metadata) ? $client->metadata : [];
        $creditMeta = is_array($credit->metadata) ? $credit->metadata : [];

        $tz = \App\Helpers\TimezoneHelper::timezoneForCountryCode($credit->country_code) ?: 'America/Bogota';

        $installments = CollectionInstallment::query()
            ->where('company_id', $companyId)
            ->where('credit_id', $credit->id)
            ->whereNull('deleted_at')
            ->orderBy('installment_number')
            ->get();

        $additions = CollectionCapitalAddition::query()
            ->where('company_id', $companyId)
            ->where('credit_id', $credit->id)
            ->orderBy('business_date')
            ->orderBy('id')
            ->get();

        // Los pagos borrados no cuentan: el cartón muestra la cuenta real.
        $payments = DB::connection(self::CONNECTION)
            ->table('collection_payments')
            ->where('company_id', $companyId)
            ->where('credit_id', $credit->id)
            ->whereNull('deleted_at')
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        $totalAdditions = (float) $additions->sum('amount');
        $originalDisbursement = round((float) $credit->amount - $totalAdditions, 2);

        $principalPaid = (float) $installments->sum('principal_paid');
        $interestPaid = (float) $installments->sum('interest_paid');
        $totalPaid = (float) $installments->sum('paid_amount');

        $livePrincipal = max(0, round((float) $credit->amount - $principalPaid, 2));
        $pendingInterest = max(0, round(
            (float) $installments->sum('interest_amount') - $interestPaid,
            2
        ));

        // Cuota vigente: la única abierta en el modo de crédito abierto.
        $openInstallment = $installments->first(
            fn ($i) => in_array(strtolower((string) $i->status), ['pendiente', 'parcial'], true)
        );

        // ── Cuotas de interés: las reales, con su estado ─────────────────────
        $installmentRows = $installments->map(function ($i) {
            $due = (float) $i->amount;
            $paid = (float) $i->paid_amount;
            $status = strtolower((string) $i->status);

            return [
                'number' => (int) $i->installment_number,
                'due_date' => optional($i->due_date)->toDateString(),
                'amount' => $due,
                'paid_amount' => $paid,
                'pending' => max(0, round($due - $paid, 2)),
                'status' => $status,
                'is_paid' => $status === 'pagado' || $paid >= $due,
                'last_payment_at' => optional($i->last_payment_at)->toISOString(),
                'payment_method' => $i->payment_method,
            ];
        })->values()->all();

        // Si la última cuota quedó paga y el crédito sigue vivo, el cliente
        // necesita saber cuándo y cuánto es la siguiente. Todavía no existe en
        // base (se genera al pagar), así que se proyecta: mismo día del mes
        // siguiente, interés sobre el capital vigente.
        $nextProjected = null;
        $lastInstallment = $installments->last();
        $creditIsOpen = strtolower((string) $credit->status) === 'active';

        if ($creditIsOpen && $lastInstallment && !$openInstallment && $livePrincipal > 0) {
            $nextProjected = [
                'number' => (int) $lastInstallment->installment_number + 1,
                'due_date' => optional($lastInstallment->due_date)
                    ? Carbon::parse($lastInstallment->due_date)->addMonthNoOverflow()->toDateString()
                    : null,
                'amount' => round($livePrincipal * (float) $credit->interest_rate / 100, 2),
            ];
        }

        // ── Libro de movimientos con saldo de capital corriente ──────────────
        $movements = [];

        $movements[] = [
            'date' => optional($credit->business_date)->toDateString(),
            'concept' => 'Desembolso inicial',
            'kind' => 'disbursement',
            'principal' => $originalDisbursement,
            'interest' => 0.0,
            'total' => $originalDisbursement,
            'detail' => $creditMeta['transfer_bank_name'] ?? null,
            'sort' => 0,
        ];

        foreach ($additions as $addition) {
            $movements[] = [
                'date' => optional($addition->business_date)->toDateString(),
                'concept' => 'Adición de capital',
                'kind' => 'addition',
                'principal' => (float) $addition->amount,
                'interest' => 0.0,
                'total' => (float) $addition->amount,
                'detail' => trim(implode(' · ', array_filter([
                    $addition->payment_method,
                    $addition->reference_number ? 'Ref: ' . $addition->reference_number : null,
                ]))) ?: null,
                'sort' => 1,
            ];
        }

        foreach ($payments as $payment) {
            $movements[] = [
                'date' => $payment->payment_date
                    ? Carbon::parse($payment->payment_date)->toDateString()
                    : null,
                'concept' => 'Abono',
                'kind' => 'payment',
                'principal' => -1 * (float) ($payment->principal_paid ?? 0),
                'interest' => (float) ($payment->interest_paid ?? 0),
                'total' => (float) $payment->amount_paid,
                'detail' => trim(implode(' · ', array_filter([
                    $payment->payment_method,
                    $payment->notes,
                ]))) ?: null,
                'sort' => 2,
            ];
        }

        usort($movements, function ($a, $b) {
            $cmp = strcmp((string) $a['date'], (string) $b['date']);
            return $cmp !== 0 ? $cmp : ($a['sort'] <=> $b['sort']);
        });

        $running = 0.0;
        foreach ($movements as $index => $movement) {
            $running = round($running + $movement['principal'], 2);
            $movements[$index]['balance'] = $running;
            unset($movements[$index]['sort']);
        }

        $company = \App\Models\Company::find($companyId);

        return [
            'company' => [
                'name' => $company->name ?? 'Deuda & Abono',
            ],
            'client' => [
                'name' => $client->name ?? 'Sin nombre',
                'dni' => $client->dni ?? null,
                'phone' => $client->phone ?? null,
                'address' => $client->address ?? null,
                'reference' => $clientMeta['reference'] ?? null,
                'profile_photo' => $clientMeta['profile_photo'] ?? null,
            ],
            'credit' => [
                'id' => $credit->id,
                'status' => $credit->status,
                'currency' => $credit->currency ?: 'COP',
                'country_code' => $credit->country_code,
                'route_name' => $credit->route_name ?? null,
                'description' => $credit->description ?? null,
                'business_date' => optional($credit->business_date)->toDateString(),
                'interest_rate' => (float) $credit->interest_rate,
                'original_amount' => $originalDisbursement,
                'current_amount' => (float) $credit->amount,
                // Dos cuotas distintas, y hay que mostrar las dos: agregar capital
                // NO recalcula la cuota vigente (regla de addCapital), así que el
                // interés que se cobra ahora puede ser menor al que corresponde al
                // capital actual. Si el cartón mostrara solo el segundo, el cliente
                // vería un número que no coincide con lo que le están cobrando.
                // Siempre sobre CAPITAL VIVO, no sobre `amount`: en un crédito
                // abierto el cliente amortiza y el interés del mes siguiente baja.
                'current_period_interest' => $openInstallment
                    ? (float) $openInstallment->interest_amount
                    : round($livePrincipal * (float) $credit->interest_rate / 100, 2),
                'next_period_interest' => round($livePrincipal * (float) $credit->interest_rate / 100, 2),
                'next_due_date' => optional($openInstallment?->due_date)->toDateString(),
            ],
            'summary' => [
                'live_principal' => $livePrincipal,
                'pending_interest' => $pendingInterest,
                'payoff_total' => round($livePrincipal + $pendingInterest, 2),
                'total_paid' => round($totalPaid, 2),
                'principal_paid' => round($principalPaid, 2),
                'interest_paid' => round($interestPaid, 2),
                'additions_total' => round($totalAdditions, 2),
                'additions_count' => $additions->count(),
            ],
            'installments' => $installmentRows,
            'next_installment' => $nextProjected,
            'movements' => $movements,
            'issued_at' => Carbon::now($tz)->toDateTimeString(),
            'timezone' => $tz,
        ];
    }

    /**
     * Etiquetas y forma de render de los campos auditados. Cubre tanto el
     * crédito como sus adiciones de capital: ambas trazas viven en
     * collection_credit_audits y se muestran en la misma línea de tiempo.
     */
    private const AUDIT_LABELS = [
        // Crédito
        'amount' => ['Monto del crédito', 'money'],
        'interest_rate' => ['Tasa de interés', 'percent'],
        'first_installment_date' => ['Fecha de la primera cuota', 'date'],
        'business_date' => ['Fecha del crédito', 'date'],
        'route_name' => ['Nombre de la ruta', 'text'],
        'description' => ['Descripción', 'text'],
        'transfer_bank_name' => ['Banco de la transferencia', 'text'],
        'transfer_reference_number' => ['Referencia de la transferencia', 'text'],
        'transfer_voucher_photo' => ['Comprobante de transferencia', 'image'],
        'transfer_support_photo' => ['Soporte adicional', 'image'],
        // Adición de capital
        'addition_amount' => ['Monto de la adición', 'money'],
        'addition_business_date' => ['Fecha de la adición', 'date'],
        'payment_method' => ['Método de pago', 'text'],
        'reference_number' => ['Referencia', 'text'],
        'bank_name' => ['Banco', 'text'],
        'notes' => ['Notas', 'text'],
        'voucher_photo' => ['Comprobante', 'image'],
    ];

    /**
     * Historial de cambios de un crédito y de sus adiciones de capital.
     * Devuelve, por evento, solo los campos que efectivamente cambiaron.
     */
    public function history(int $creditId, ?int $requestedCompanyId = null, int $limit = 200)
    {
        $companyId = $this->resolveCompanyId($requestedCompanyId);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $exists = CollectionCredit::query()
            ->where('id', $creditId)
            ->where('company_id', $companyId)
            ->exists();
        if (!$exists) {
            return $this->errorNotFoundResponse('Crédito Collection no encontrado');
        }

        $audits = \App\Models\Collection\CollectionCreditAudit::query()
            ->where('company_id', $companyId)
            ->where('credit_id', $creditId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        // Los nombres de usuario viven en MySQL (core), no en collection_pgsql.
        $userIds = $audits->pluck('user_id')->filter()->unique()->all();
        $names = empty($userIds)
            ? collect()
            : \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id');

        $data = $audits->map(function ($audit) use ($names) {
            $changes = is_array($audit->changes) ? $audit->changes : [];

            return [
                'id' => $audit->id,
                'action' => $audit->action,
                'addition_id' => $changes['addition_id'] ?? null,
                'user_id' => $audit->user_id,
                'user_name' => $names[$audit->user_id] ?? null,
                'ip_address' => $audit->ip_address,
                'created_at' => optional($audit->created_at)->toISOString(),
                'fields' => $this->describeCreditAuditChanges($changes),
            ];
        })->values();

        return $this->successResponse([
            'success' => true,
            'data' => $data,
        ]);
    }

    /** Compara old/new y devuelve solo lo que cambió, con etiqueta y tipo. */
    private function describeCreditAuditChanges(array $changes): array
    {
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];
        $new = is_array($changes['new'] ?? null) ? $changes['new'] : [];

        $fields = [];
        foreach (self::AUDIT_LABELS as $field => [$label, $type]) {
            if (!array_key_exists($field, $old) && !array_key_exists($field, $new)) {
                continue;
            }

            $oldValue = $this->normalizeCreditAuditValue($old[$field] ?? null, $type);
            $newValue = $this->normalizeCreditAuditValue($new[$field] ?? null, $type);

            if ($oldValue === $newValue) {
                continue;
            }

            $fields[] = [
                'field' => $field,
                'label' => $label,
                'type' => $type,
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return $fields;
    }

    /** Normaliza a string|null; los números se comparan ya redondeados. */
    private function normalizeCreditAuditValue($value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        if (in_array($type, ['money', 'percent'], true)) {
            return number_format((float) $value, 2, '.', '');
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Agrega capital a un credito existente en modo monthly_interest_open.
     *
     * Regla de negocio:
     *  - Solo aplicable a creditos con status = 'active'.
     *  - La cuota VIGENTE se recalcula: el interes mensual siempre es
     *    capital vivo x tasa. (Antes no se tocaba y quedaba cobrando el interes
     *    del capital anterior, que no es lo que el cliente debe.)
     *  - Las cuotas ya pagadas no se tocan: ese periodo se cobro con el capital
     *    que habia entonces.
     *  - Sin limite superior: se puede agregar cualquier monto.
     *  - Genera movimiento en wallet tipo debit (action=capital_addition).
     */
    public function addCapital(int $creditId, array $payload)
    {
        $companyId = $this->resolveCompanyId($payload['company_id'] ?? null);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $added = (float) ($payload['amount'] ?? 0);
        if ($added <= 0) {
            return $this->errorResponse('El monto a agregar debe ser mayor a 0', 422);
        }

        $credit = CollectionCredit::query()
            ->where('id', $creditId)
            ->where('company_id', $companyId)
            ->first();

        if (!$credit) {
            return $this->errorNotFoundResponse('Crédito Collection no encontrado');
        }

        if ($credit->status !== 'active') {
            return $this->errorResponse('Solo se puede agregar capital a créditos activos', 422);
        }

        // Día contable de la adición, anclado a la zona horaria del país del
        // crédito. Antes usaba Carbon::now() a secas, que corre en UTC: una
        // adición hecha de noche en Bogotá (UTC-5) se contabilizaba en el día
        // siguiente y descuadraba el corte de caja.
        $additionTz = \App\Helpers\TimezoneHelper::timezoneForCountryCode($credit->country_code) ?: 'America/Bogota';
        $businessDate = $payload['business_date'] ?? Carbon::now($additionTz)->toDateString();
        if ($this->closureSvc->isDayClosed($companyId, $businessDate)) {
            return $this->errorResponse(
                'No se puede agregar capital: la caja del día ' . $businessDate . ' está cerrada. El corte del día ya es definitivo.',
                409
            );
        }

        $this->partitionService->ensurePartitions($companyId);

        return DB::connection(self::CONNECTION)->transaction(function () use ($credit, $added, $payload, $companyId, $businessDate) {

            // 1) Registro de la adicion (trazabilidad)
            $addition = CollectionCapitalAddition::query()->create([
                'company_id' => $companyId,
                'credit_id' => $credit->id,
                'amount' => $added,
                'business_date' => $businessDate,
                'payment_method' => $payload['payment_method'] ?? null,
                'reference_number' => $payload['reference_number'] ?? null,
                'bank_name' => $payload['bank_name'] ?? null,
                'voucher_photo' => $payload['voucher_photo'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            // 2) Actualizar amount del credito (nuevo principal)
            $previousAmount = (float) $credit->amount;
            $newAmount = round($previousAmount + $added, 2);
            $credit->amount = $newAmount;
            $credit->save();

            // 2b) El interes sigue al capital: la cuota vigente se recalcula ya.
            $this->syncOpenInstallmentInterest($credit, $companyId);

            // 3) Wallet movement: debit por el capital entregado
            app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
                'company_id' => $companyId,
                'currency' => $credit->currency ?? 'COP',
                'country_code' => $credit->country_code ?? 'CO',
                'amount' => $added,
                'type' => 'debit',
                'action_type' => 'capital_addition',
                'reference_type' => 'credit',
                'reference_id' => $credit->id,
                'description' => "Adición de capital al crédito #{$credit->id}",
            ]);

            $nextQuota = round($newAmount * (float) $credit->interest_rate / 100, 2);

            return $this->successCreatedResponse([
                'success' => true,
                'message' => 'Capital agregado al crédito',
                'data' => [
                    'addition_id' => $addition->id,
                    'credit_id' => $credit->id,
                    'previous_amount' => $previousAmount,
                    'added_amount' => $added,
                    'new_amount' => $newAmount,
                    'next_quota_amount' => $nextQuota,
                ],
            ]);
        });
    }

    /**
     * Anula un crédito dentro de la ventana del mismo día.
     *
     * No borra la fila: la marca como 'anulado'. Un crédito que existió y movió
     * caja no puede desaparecer del histórico — el corte del día, los reportes
     * y la auditoría necesitan poder explicar ese dinero.
     *
     * Lo que hace:
     *  - devuelve a la caja TODO lo que salió por este crédito (desembolso +
     *    adiciones), con un movimiento nuevo append-only;
     *  - marca las cuotas como borradas (quién y desde qué IP);
     *  - deja la traza completa en collection_credit_audits.
     *
     * Se bloquea si ya hay abonos: primero hay que reversar los pagos, porque
     * devolver el capital dejaría la caja descuadrada respecto de lo cobrado.
     */
    public function destroy(int $creditId, array $payload)
    {
        $companyId = $this->resolveCompanyId($payload['company_id'] ?? null);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $credit = CollectionCredit::query()
            ->where('id', $creditId)
            ->where('company_id', $companyId)
            ->first();

        if (!$credit) {
            return $this->errorNotFoundResponse('Crédito Collection no encontrado');
        }

        if ($credit->status === 'anulado') {
            return $this->errorResponse('Este crédito ya está anulado.', 409);
        }

        // Se reusa la ventana de edición, pero el mensaje de "solo activos" se
        // lee raro al anular, así que el caso ya anulado se atiende arriba.
        [$editable, $reason] = $this->creditEditability($credit);
        if (!$editable) {
            return $this->errorResponse($reason, 409);
        }

        $paid = (float) CollectionInstallment::query()
            ->where('company_id', $companyId)
            ->where('credit_id', $credit->id)
            ->whereNull('deleted_at')
            ->sum('paid_amount');

        if ($paid > 0) {
            return $this->errorResponse(
                'El crédito ya tiene abonos registrados: no se puede anular. Reversá los pagos primero.',
                409
            );
        }

        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            return $this->errorResponse('Indicá el motivo de la anulación', 422);
        }

        return DB::connection(self::CONNECTION)->transaction(function () use ($credit, $companyId, $reason) {
            $additions = CollectionCapitalAddition::query()
                ->where('company_id', $companyId)
                ->where('credit_id', $credit->id)
                ->get();

            // Todo lo que salió de caja por este crédito es su `amount` actual:
            // ya incluye las adiciones y los ajustes por edición.
            $reversal = round((float) $credit->amount, 2);

            $meta = is_array($credit->metadata) ? $credit->metadata : [];
            $oldSnapshot = [
                'amount' => (float) $credit->amount,
                'interest_rate' => (float) $credit->interest_rate,
                'business_date' => optional($credit->business_date)->toDateString(),
                'status' => $credit->status,
                'route_name' => $credit->route_name ?? null,
                'description' => $credit->description ?? null,
                'additions_count' => $additions->count(),
                'additions_total' => (float) $additions->sum('amount'),
            ];

            // Cuotas: se marcan borradas, no se eliminan.
            CollectionInstallment::query()
                ->where('company_id', $companyId)
                ->where('credit_id', $credit->id)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => Carbon::now(),
                    'deleted_by' => Auth::id(),
                    'deleted_ip' => request()->ip(),
                ]);

            $credit->status = 'anulado';
            $meta['cancelled_by'] = Auth::id();
            $meta['cancelled_at'] = Carbon::now()->toISOString();
            $meta['cancelled_reason'] = $reason;
            $credit->metadata = $meta;
            $credit->save();

            if ($reversal >= 0.01) {
                app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
                    'company_id' => $companyId,
                    'currency' => strtoupper($credit->currency ?: 'COP'),
                    'country_code' => strtoupper($credit->country_code ?: 'CO'),
                    'amount' => $reversal,
                    'type' => 'credit',
                    'action_type' => 'loan_cancellation',
                    'reference_type' => 'credit',
                    'reference_id' => $credit->id,
                    'description' => "Anulación del crédito #{$credit->id}: reintegro de "
                        . number_format($reversal, 2) . ' a caja',
                ]);
            }

            \App\Models\Collection\CollectionCreditAudit::query()->create([
                'company_id' => $companyId,
                'credit_id' => $credit->id,
                'action' => 'cancelled',
                'user_id' => Auth::id(),
                'ip_address' => request()->ip(),
                'changes' => [
                    'reason' => $reason,
                    'reversed_amount' => $reversal,
                    'old' => $oldSnapshot,
                    'new' => ['status' => 'anulado'],
                ],
            ]);

            return $this->successResponse([
                'success' => true,
                'message' => 'Crédito anulado y reintegrado a caja',
                'data' => [
                    'id' => $credit->id,
                    'reversed_amount' => $reversal,
                ],
            ]);
        });
    }

    /**
     * Recalcula el interés de la cuota vigente contra el capital vivo.
     *
     * Regla: el interés mensual SIEMPRE es CAPITAL VIVO × tasa, donde el capital
     * vivo es `amount` menos el capital ya devuelto. No es `amount` a secas: en
     * un crédito abierto el cliente amortiza capital y el interés del mes
     * siguiente baja (ver crédito 6: 50 → 45 → 39,75 → 34,24). Calcularlo sobre
     * `amount` le cobraría intereses sobre plata que ya devolvió.
     *
     * Si se agrega (o se corrige) capital, la cuota abierta se actualiza en el
     * acto — antes quedaba con el interés del capital viejo.
     *
     * No toca cuotas ya pagadas: ese período se cobró con el capital que había
     * entonces y reescribirlo falsearía el histórico. Si la cuota tiene pagos
     * parciales sí se recalcula: lo abonado se conserva y la diferencia queda
     * pendiente.
     */
    private function syncOpenInstallmentInterest(CollectionCredit $credit, int $companyId): void
    {
        $open = CollectionInstallment::query()
            ->where('company_id', $companyId)
            ->where('credit_id', $credit->id)
            ->whereNull('deleted_at')
            ->whereIn(DB::raw('LOWER(status)'), ['pendiente', 'parcial'])
            ->orderBy('installment_number')
            ->first();

        if (!$open) {
            return;
        }

        $principalPaid = (float) CollectionInstallment::query()
            ->where('company_id', $companyId)
            ->where('credit_id', $credit->id)
            ->whereNull('deleted_at')
            ->sum('principal_paid');

        $livePrincipal = max(0, round((float) $credit->amount - $principalPaid, 2));

        $interest = round($livePrincipal * (float) $credit->interest_rate / 100, 2);
        $interestPaid = (float) ($open->interest_paid ?? 0);

        $open->amount = $interest;
        $open->interest_amount = $interest;
        $open->principal_amount = 0;
        // Si lo ya abonado alcanza el nuevo interés, la cuota queda saldada.
        $open->status = $interestPaid >= $interest ? 'pagado' : ($interestPaid > 0 ? 'parcial' : 'pendiente');
        $open->save();
    }

    /**
     * ¿Se puede corregir esta adición de capital?
     *
     * Misma ventana que el crédito y por los mismos motivos: día real de
     * registro (ver localRegistrationDate) y caja del día contable abierta.
     */
    public function capitalAdditionEditability(CollectionCapitalAddition $addition, ?CollectionCredit $credit = null): array
    {
        $credit = $credit ?: CollectionCredit::find($addition->credit_id);
        if (!$credit) {
            return [false, 'El crédito de la adición no existe.'];
        }

        if ($credit->status !== 'active') {
            return [false, 'El crédito ya no está activo.'];
        }

        $tz = \App\Helpers\TimezoneHelper::timezoneForCountryCode($credit->country_code) ?: 'America/Bogota';
        $today = Carbon::now($tz)->toDateString();

        $registeredOn = $this->localRegistrationDate($addition, $tz)
            ?: optional($addition->business_date)->toDateString();

        if ($registeredOn !== $today) {
            return [false, 'Una adición solo puede editarse el mismo día en que se registró.'];
        }

        $businessDate = optional($addition->business_date)->toDateString() ?: $today;

        if ($this->closureSvc->isDayClosed($credit->company_id, $businessDate)) {
            return [false, 'La caja del día ' . $businessDate . ' está cerrada: el corte ya es definitivo.'];
        }

        return [true, null];
    }

    /**
     * Corrige una adición de capital dentro de la ventana del mismo día.
     *
     * Cambiar el monto arrastra dos efectos, porque así se creó:
     *  - el `amount` del crédito se mueve por el delta (la adición lo había subido)
     *  - la wallet se ajusta por el delta con un movimiento nuevo (append-only)
     *
     * La cuota vigente NO se toca, igual que al agregar capital: el nuevo monto
     * se refleja recién en la cuota siguiente.
     */
    public function updateCapitalAddition(int $additionId, array $payload)
    {
        $companyId = $this->resolveCompanyId($payload['company_id'] ?? null);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $addition = CollectionCapitalAddition::query()
            ->where('id', $additionId)
            ->where('company_id', $companyId)
            ->first();

        if (!$addition) {
            return $this->errorNotFoundResponse('Adición de capital no encontrada');
        }

        $credit = CollectionCredit::query()
            ->where('id', $addition->credit_id)
            ->where('company_id', $companyId)
            ->first();

        [$editable, $reason] = $this->capitalAdditionEditability($addition, $credit);
        if (!$editable) {
            return $this->errorResponse($reason, 409);
        }

        $oldAmount = (float) $addition->amount;
        $newAmount = array_key_exists('amount', $payload) && $payload['amount'] !== null
            ? (float) $payload['amount']
            : $oldAmount;

        if ($newAmount <= 0) {
            return $this->errorResponse('El monto de la adición debe ser mayor a 0', 422);
        }

        $tz = \App\Helpers\TimezoneHelper::timezoneForCountryCode($credit->country_code) ?: 'America/Bogota';
        $todayInTz = Carbon::now($tz)->toDateString();
        $oldBusinessDate = optional($addition->business_date)->toDateString() ?: $todayInTz;
        $newBusinessDate = !empty($payload['business_date'])
            ? Carbon::parse($payload['business_date'])->toDateString()
            : $oldBusinessDate;

        if ($newBusinessDate !== $oldBusinessDate) {
            if ($newBusinessDate > $todayInTz) {
                return $this->errorResponse('La fecha de la adición no puede ser futura', 422);
            }
            if ($this->closureSvc->isDayClosed($companyId, $newBusinessDate)) {
                return $this->errorResponse(
                    'No se puede mover la adición al ' . $newBusinessDate . ': la caja de ese día está cerrada.',
                    409
                );
            }
        }

        // Bajar el monto no puede dejar el crédito en negativo.
        $delta = round($newAmount - $oldAmount, 2);
        if (round((float) $credit->amount + $delta, 2) <= 0) {
            return $this->errorResponse(
                'El monto deja el crédito en cero o negativo. Revisá el valor.',
                422
            );
        }

        return DB::connection(self::CONNECTION)->transaction(function () use (
            $addition, $credit, $companyId, $payload,
            $oldAmount, $newAmount, $oldBusinessDate, $newBusinessDate, $delta
        ) {
            $oldSnapshot = [
                'addition_amount' => $oldAmount,
                'addition_business_date' => $oldBusinessDate,
                'payment_method' => $addition->payment_method,
                'reference_number' => $addition->reference_number,
                'bank_name' => $addition->bank_name,
                'notes' => $addition->notes,
                'voucher_photo' => $addition->voucher_photo,
            ];

            $addition->amount = $newAmount;
            $addition->business_date = $newBusinessDate;

            foreach (['payment_method', 'reference_number', 'bank_name', 'notes'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $addition->{$field} = $payload[$field] ?: null;
                }
            }
            // La imagen anterior no se borra: la traza la sigue mostrando.
            if (!empty($payload['voucher_photo'])) {
                $addition->voucher_photo = $payload['voucher_photo'];
            }
            $addition->save();

            if (abs($delta) >= 0.01) {
                // El crédito había subido por la adición: se mueve por el delta.
                $credit->amount = round((float) $credit->amount + $delta, 2);
                $credit->save();

                // El interés sigue al capital, también al corregir la adición.
                $this->syncOpenInstallmentInterest($credit, $companyId);

                app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
                    'company_id' => $companyId,
                    'currency' => strtoupper($credit->currency ?: 'COP'),
                    'country_code' => strtoupper($credit->country_code ?: 'CO'),
                    'amount' => abs($delta),
                    'type' => $delta > 0 ? 'debit' : 'credit',
                    'action_type' => 'capital_addition_adjustment',
                    'reference_type' => 'credit',
                    'reference_id' => $credit->id,
                    'description' => "Ajuste por edición de adición #{$addition->id} del crédito #{$credit->id}: "
                        . number_format($oldAmount, 2) . ' → ' . number_format($newAmount, 2),
                ]);
            }

            \App\Models\Collection\CollectionCreditAudit::query()->create([
                'company_id' => $companyId,
                'credit_id' => $credit->id,
                'action' => 'addition_updated',
                'user_id' => Auth::id(),
                'ip_address' => request()->ip(),
                'changes' => [
                    'addition_id' => $addition->id,
                    'old' => $oldSnapshot,
                    'new' => [
                        'addition_amount' => (float) $addition->amount,
                        'addition_business_date' => optional($addition->business_date)->toDateString(),
                        'payment_method' => $addition->payment_method,
                        'reference_number' => $addition->reference_number,
                        'bank_name' => $addition->bank_name,
                        'notes' => $addition->notes,
                        'voucher_photo' => $addition->voucher_photo,
                    ],
                ],
            ]);

            return $this->successResponse([
                'success' => true,
                'message' => 'Adición de capital actualizada',
                'data' => [
                    'id' => $addition->id,
                    'amount' => (float) $addition->amount,
                    'credit_amount' => (float) $credit->amount,
                ],
            ]);
        });
    }

    /**
     * Lista las adiciones de capital hechas sobre un credito (historial).
     * Orden: mas recientes primero. Incluye el nombre del usuario que las hizo.
     */
    public function listCapitalAdditions(int $creditId, ?int $companyId = null)
    {
        $companyId = $this->resolveCompanyId($companyId);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $credit = CollectionCredit::query()
            ->where('id', $creditId)
            ->where('company_id', $companyId)
            ->first();
        if (!$credit) {
            return $this->errorNotFoundResponse('Crédito Collection no encontrado');
        }

        $rows = CollectionCapitalAddition::query()
            ->where('credit_id', $creditId)
            ->where('company_id', $companyId)
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->get();

        // Hidratar nombre del usuario (viene del schema MySQL, conexion separada).
        $userIds = $rows->pluck('created_by')->filter()->unique()->values()->all();
        $users = [];
        if (!empty($userIds)) {
            $users = \App\Models\User::query()
                ->whereIn('id', $userIds)
                ->pluck('name', 'id')
                ->all();
        }

        $data = $rows->map(function ($r) use ($users, $credit) {
            [$canEdit, $editReason] = $this->capitalAdditionEditability($r, $credit);

            return [
                'id'               => $r->id,
                'amount'           => (float) $r->amount,
                'business_date'    => optional($r->business_date)->toDateString(),
                'payment_method'   => $r->payment_method,
                'reference_number' => $r->reference_number,
                'bank_name'        => $r->bank_name,
                'notes'            => $r->notes,
                'voucher_photo'    => $r->voucher_photo,
                'created_by'       => $r->created_by,
                'created_by_name'  => $users[$r->created_by] ?? null,
                'created_at'       => optional($r->created_at)->toISOString(),
                'can_edit'         => $canEdit,
                'edit_blocked_reason' => $editReason,
            ];
        })->values();

        return $this->successResponse([
            'success' => true,
            'data' => [
                'items' => $data,
                'total_count' => $data->count(),
                'total_amount' => (float) $rows->sum('amount'),
            ],
        ]);
    }

    private function hasCreditColumn(string $column): bool
    {
        return Schema::connection(self::CONNECTION)->hasColumn('collection_credits', $column);
    }

    private function resolveCompanyId($requestedCompanyId): int
    {
        if (!empty($requestedCompanyId)) {
            return (int) $requestedCompanyId;
        }

        $user = Auth::user();
        $companyId = null;
        if ($user && $user->company && !empty($user->company->id)) {
            $companyId = (int) $user->company->id;
        } elseif ($user && $user->seller && !empty($user->seller->company_id)) {
            $companyId = (int) $user->seller->company_id;
        }

        // Fail-closed: Collection es multi-tenant estricto por empresa. Si no hay
        // empresa resoluble, cortamos con 422 en lugar de devolver null (que
        // degradaría a WHERE company_id IS NULL y podría filtrar datos si un
        // caller futuro olvidara validar). El caso normal nunca llega aquí: el
        // trait ResolvesCollectionCompany ya inyectó un company_id válido.
        abort_if($companyId === null, 422, 'No se pudo resolver la empresa para la operación de Collection.');

        return $companyId;
    }



    public function generateInstallments(CollectionCredit $credit, array $excludedDays = []): void
    {
        $this->partitionService->ensurePartitions($credit->company_id);

        $principal = (float) $credit->amount;
        $interestRate = (float) $credit->interest_rate;

        $meta = is_array($credit->metadata) ? $credit->metadata : [];
        $mode = $meta['installment_distribution_mode'] ?? 'monthly_interest_open';

        // Modo unico actual: credito abierto.
        // Genera 1 sola cuota de interes; las siguientes se crean al pagar.
        if ($mode === 'monthly_interest_open') {
            $interest = round(($principal * $interestRate) / 100, 2);
            $firstDate = Carbon::parse($credit->first_installment_date);
            DB::connection(self::CONNECTION)->table('collection_installments')->insert([
                'company_id' => $credit->company_id,
                'credit_id' => $credit->id,
                'installment_number' => 1,
                'due_date' => $firstDate->toDateString(),
                'amount' => $interest,
                'principal_amount' => 0,
                'interest_amount' => $interest,
                'paid_amount' => 0,
                'principal_paid' => 0,
                'interest_paid' => 0,
                'status' => 'pendiente',
                'recorded_at' => Carbon::now(),
            ]);
            return;
        }

        // Fallback para creditos legacy con modos anteriores (capital_interest / interest_only).
        // No aplica a creditos nuevos, pero se deja por compatibilidad con datos existentes.
        $count = (int) $credit->total_installments;
        if ($count <= 0) return;

        $totalInterest = ($principal * $interestRate) / 100;
        $principalPerInstallment = $principal / $count;
        $interestPerInstallment = $totalInterest / $count;

        $currentDate = Carbon::parse($credit->first_installment_date);
        $frequency = $credit->payment_frequency ?? 'Diaria';

        // Normalize excluded days to lowercase for comparison
        $excludedDays = array_map('strtolower', $excludedDays);

        for ($i = 1; $i <= $count; $i++) {
            // If it's an excluded day, skip to the next valid day
            while (in_array(strtolower($currentDate->locale('en')->dayName), $excludedDays)) {
                $currentDate->addDay();
            }

            // id generado por la secuencia de PostgreSQL.
            $currentPrincipal = $principalPerInstallment;
            $currentInterest = $interestPerInstallment;

            if ($mode === 'interest_only') {
                $currentInterest = ($principal * $interestRate) / 100;
                if ($i < $count) {
                    $currentPrincipal = 0;
                } else {
                    $currentPrincipal = $principal;
                }
            }

            DB::connection(self::CONNECTION)->table('collection_installments')->insert([
                'company_id' => $credit->company_id,
                'credit_id' => $credit->id,
                'installment_number' => $i,
                'due_date' => $currentDate->toDateString(),
                'amount' => round($currentPrincipal + $currentInterest, 2),
                'principal_amount' => round($currentPrincipal, 2),
                'interest_amount' => round($currentInterest, 2),
                'paid_amount' => 0,
                'principal_paid' => 0,
                'interest_paid' => 0,
                'status' => 'pendiente',
                'recorded_at' => Carbon::now(),
            ]);

            // Advance for next installment
            switch ($frequency) {
                case 'Diaria': $currentDate->addDay(); break;
                case 'Semanal': $currentDate->addWeek(); break;
                case 'Quincenal': $currentDate->addDays(15); break;
                case 'Mensual': $currentDate->addMonth(); break;
                default: $currentDate->addDay(); break;
            }
        }
    }

    /**
     * Liquida un credito abierto: paga el interes pendiente + todo el capital restante en
     * una sola operacion, marca la cuota actual como pagada y cierra el credito.
     */
    public function settle(int $companyId, int $creditId, array $payload)
    {
        $credit = CollectionCredit::query()
            ->where('company_id', $companyId)
            ->where('id', $creditId)
            ->first();

        if (!$credit) {
            return $this->errorNotFoundResponse('Credito no encontrado');
        }

        if ($credit->status !== 'active') {
            return $this->errorResponse('El credito no esta activo (status=' . $credit->status . ')', 422);
        }

        // Capital pendiente
        $paidPrincipal = (float) DB::connection(self::CONNECTION)
            ->table('collection_installments')
            ->where('company_id', $companyId)
            ->where('credit_id', $creditId)
            ->sum('principal_paid');
        $remainingPrincipal = round((float) $credit->amount - $paidPrincipal, 2);

        // Cuota pendiente actual (en credito abierto solo hay 1 abierta)
        $pendingInstallment = \App\Models\Collection\CollectionInstallment::query()
            ->where('company_id', $companyId)
            ->where('credit_id', $creditId)
            ->whereIn('status', ['pendiente', 'parcial'])
            ->orderBy('installment_number')
            ->first();

        $pendingInterest = 0.0;
        if ($pendingInstallment) {
            $pendingInterest = round(
                (float) $pendingInstallment->interest_amount - (float) ($pendingInstallment->interest_paid ?? 0),
                2
            );
        }

        $settlementTotal = round($remainingPrincipal + $pendingInterest, 2);

        if ($settlementTotal <= 0) {
            return $this->errorResponse('No hay saldo para liquidar', 422);
        }

        // Bloquear si el día de la liquidación tiene cierre de caja activo.
        $tzCheck = $payload['timezone'] ?? 'UTC';
        $settleDate = $payload['payment_date'] ?? Carbon::now($tzCheck)->toDateString();
        if ($this->closureSvc->isDayClosed($companyId, $settleDate)) {
            return $this->errorResponse(
                'No se puede liquidar: la caja del día ' . $settleDate . ' está cerrada. El corte del día ya es definitivo.',
                409
            );
        }

        return DB::connection(self::CONNECTION)->transaction(function () use (
            $credit, $companyId, $creditId, $payload, $pendingInstallment,
            $pendingInterest, $remainingPrincipal, $settlementTotal
        ) {
            $tz = $payload['timezone'] ?? 'UTC';
            $now = Carbon::now($tz);
            $paymentMethod = $payload['payment_method'] ?? 'Efectivo';
            $voucherPath = $payload['voucher_path'] ?? null;
            $notes = $payload['notes'] ?? 'Liquidacion de credito';

            // Si hay cuota pendiente: actualizar para reflejar pago de interes + capital total.
            if ($pendingInstallment) {
                $newInterestPaid = (float) $pendingInstallment->interest_amount;
                $newPrincipalPaid = (float) ($pendingInstallment->principal_paid ?? 0) + $remainingPrincipal;
                $newAmount = (float) $pendingInstallment->interest_amount + $remainingPrincipal;
                $newPrincipalAmount = (float) ($pendingInstallment->principal_amount ?? 0) + $remainingPrincipal;

                $pendingInstallment->update([
                    'status' => 'pagado',
                    'amount' => round($newAmount, 2),
                    'principal_amount' => round($newPrincipalAmount, 2),
                    'paid_amount' => round($newInterestPaid + $newPrincipalPaid, 2),
                    'interest_paid' => round($newInterestPaid, 2),
                    'principal_paid' => round($newPrincipalPaid, 2),
                    'payment_method' => $paymentMethod,
                    'notes' => $notes,
                    'voucher_path' => $voucherPath,
                    'last_payment_at' => $now,
                ]);

                \App\Models\Collection\CollectionPayment::create([
                    'company_id' => $companyId,
                    'credit_id' => $creditId,
                    'installment_number' => $pendingInstallment->installment_number,
                    'amount_paid' => $settlementTotal,
                    'payment_date' => $payload['payment_date'] ?? $now->toDateString(),
                    'payment_method' => $paymentMethod,
                    'notes' => $notes,
                    'voucher_path' => $voucherPath,
                    'recorded_at' => $now,
                ]);
            }

            // Cerrar credito
            $credit->update(['status' => 'pagado']);

            // Wallet: ingreso por liquidacion
            app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
                'company_id' => $companyId,
                'currency' => $credit->currency ?? 'COP',
                'country_code' => $credit->country_code ?? 'CO',
                'amount' => $settlementTotal,
                'type' => 'credit',
                'action_type' => 'payment',
                'reference_type' => 'credit',
                'reference_id' => $creditId,
                'description' => "Liquidacion de credito #{$creditId}",
            ]);

            return $this->successResponse([
                'success' => true,
                'message' => 'Credito liquidado con exito',
                'settled_total' => $settlementTotal,
                'paid_interest' => $pendingInterest,
                'paid_principal' => $remainingPrincipal,
            ]);
        });
    }

    /**
     * Credito abierto: cuando todas las cuotas existentes estan pagadas, crea la siguiente
     * cuota mensual de interes sobre el capital pendiente. Si el capital ya se pago totalmente,
     * cierra el credito.
     */
    public function generateNextOpenEndedInstallment(CollectionCredit $credit): void
    {
        $meta = is_array($credit->metadata) ? $credit->metadata : [];
        if (empty($meta['is_open_ended'])) {
            return;
        }

        // Capital pendiente = credit.amount - suma(principal_paid).
        $paidPrincipal = (float) DB::connection(self::CONNECTION)
            ->table('collection_installments')
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->sum('principal_paid');

        $remainingPrincipal = round((float) $credit->amount - $paidPrincipal, 2);

        if ($remainingPrincipal <= 0) {
            $credit->update(['status' => 'pagado']);
            return;
        }

        // Solo generar siguiente si no hay ninguna cuota pendiente o parcial.
        $hasPending = DB::connection(self::CONNECTION)
            ->table('collection_installments')
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->whereIn('status', ['pendiente', 'parcial'])
            ->exists();

        if ($hasPending) {
            return;
        }

        $lastInstallment = DB::connection(self::CONNECTION)
            ->table('collection_installments')
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->orderByDesc('installment_number')
            ->first();

        if (!$lastInstallment) {
            return;
        }

        $nextNumber = (int) $lastInstallment->installment_number + 1;
        $nextDue = Carbon::parse($lastInstallment->due_date)->addMonth()->toDateString();
        $interest = round(($remainingPrincipal * (float) $credit->interest_rate) / 100, 2);

        DB::connection(self::CONNECTION)->table('collection_installments')->insert([
            'company_id' => $credit->company_id,
            'credit_id' => $credit->id,
            'installment_number' => $nextNumber,
            'due_date' => $nextDue,
            'amount' => $interest,
            'principal_amount' => 0,
            'interest_amount' => $interest,
            'paid_amount' => 0,
            'principal_paid' => 0,
            'interest_paid' => 0,
            'status' => 'pendiente',
            'recorded_at' => Carbon::now(),
        ]);

        $credit->increment('total_installments');
    }

    public function deleteInstallment(int $installmentId, array $securityToken = [], ?int $requestedCompanyId = null)
    {
        $companyId = $this->resolveCompanyId($requestedCompanyId);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        // Mandatory Security Validation
        $securityService = app(\App\Services\Collection\CollectionSecurityService::class);
        $requestId = $securityToken['request_id'] ?? '';
        $code = $securityToken['code'] ?? '';

        if (!$securityService->validateToken($requestId, $code, $companyId)) {
            return $this->errorResponse('Código de autorización gerencial inválido o expirado', 403);
        }

        $installment = \App\Models\Collection\CollectionInstallment::query()
            ->where('company_id', $companyId)
            ->where('id', $installmentId)
            ->first();

        if (!$installment) {
            return $this->errorNotFoundResponse('Cuota no encontrada');
        }

        return DB::connection(self::CONNECTION)->transaction(function () use ($installment, $companyId) {
            // Backup current state (with payment info) for audit
            $history = $installment->toArray();
            
            // Reversa física parcial de saldo: Marcamos los registros de la tabla de pagos como eliminados (Soft Delete manual)
            \App\Models\Collection\CollectionPayment::where('company_id', $installment->company_id)
                ->where('credit_id', $installment->credit_id)
                ->where('installment_number', $installment->installment_number)
                ->update(['deleted_at' => Carbon::now()]);

            $installment->update([
                'paid_amount' => 0,
                'status' => 'pendiente',
                'payment_method' => null,
                'notes' => null,
                'voucher_path' => null,
                'last_payment_at' => null,
                // Audit the reversal action
                'deleted_at' => Carbon::now(),
                'deleted_by' => Auth::id(),
                'deleted_ip' => request()->ip(),
                'history' => $history,
            ]);

            // Sync with Centralized Wallet (Reversal: Substract what was previously added)
            $totalReversed = (float) ($history['paid_amount'] ?? 0);
            if ($totalReversed > 0) {
                $credit = CollectionCredit::find($installment->credit_id);
                app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
                    'company_id' => $companyId,
                    'currency' => $credit->currency ?? 'COP',
                    'country_code' => $credit->country_code ?? 'CO',
                    'amount' => $totalReversed,
                    'type' => 'debit', // Reversing an income
                    'action_type' => 'payment_reversal',
                    'reference_type' => 'credit',
                    'reference_id' => $installment->credit_id,
                    'description' => "Reversión de pago cuota #{$installment->installment_number} crédito #{$installment->credit_id}",
                ]);
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Cobro/Abono eliminado correctamente. La cuota ahora está pendiente.'
            ]);
        });
    }

    /**
     * Recalcula el interes de las cuotas futuras pendientes si el capital varia.
     */
    public function recalculateFutureInstallments(CollectionCredit $credit): void
    {
        $meta = is_array($credit->metadata) ? $credit->metadata : [];
        if (empty($meta['is_open_ended'])) {
            return;
        }

        // 1. Calcular capital pendiente actual
        $paidPrincipal = (float) DB::connection(self::CONNECTION)
            ->table('collection_installments')
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->whereNull('deleted_at')
            ->sum('principal_paid');

        $remainingPrincipal = round((float) $credit->amount - $paidPrincipal, 2);

        if ($remainingPrincipal <= 0) {
            // Si el capital ya se saldo, marcar todas las pendientes como cerradas (o que el proximo pago liquide el interes)
             DB::connection(self::CONNECTION)
                ->table('collection_installments')
                ->where('company_id', $credit->company_id)
                ->where('credit_id', $credit->id)
                ->where('status', 'pendiente')
                ->update([
                    'interest_amount' => 0,
                    'amount' => 0,
                    'status' => 'pagado', // Opcional: si ya no hay deuda, se cierran
                ]);
            $credit->update(['status' => 'pagado']);
            return;
        }

        // 2. Buscar todas las cuotas PENDIENTES o PARCIALES (futuras)
        $futureInstallments = \App\Models\Collection\CollectionInstallment::query()
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->whereIn('status', ['pendiente', 'parcial'])
            ->get();

        foreach ($futureInstallments as $inst) {
            $interestRate = (float) $credit->interest_rate;
            $newInterest = round(($remainingPrincipal * $interestRate) / 100, 2);
            
            // Si ya tiene algo pagado de interes, hay que restar eso
            $pendingInterest = max(0, $newInterest - (float)($inst->interest_paid ?? 0));
            $pendingPrincipal = max(0, (float)($inst->principal_amount ?? 0) - (float)($inst->principal_paid ?? 0));
            
            $inst->update([
                'interest_amount' => $newInterest,
                'amount' => round($newInterest + (float)($inst->principal_amount ?? 0), 2),
                'paid_amount' => (float)($inst->interest_paid ?? 0) + (float)($inst->principal_paid ?? 0),
            ]);
        }
    }
}
