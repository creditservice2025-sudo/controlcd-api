<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionClient;
use App\Models\Collection\CollectionClientAudit;
use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionInstallment;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CollectionClientService
{
    use ApiResponse;

    private const CONNECTION = 'collection_pgsql';

    public function __construct(
        private readonly CollectionCreditService $creditService,
        private readonly CollectionPartitionService $partitionService
    ) {
    }

    public function list(array $filters = [])
    {
        $companyId = $this->resolveCompanyId($filters['company_id'] ?? null);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = (int) ($filters['per_page'] ?? 10);

        $query = CollectionClient::query()
            ->orderByDesc('updated_at');

        if ($this->hasClientCompanyColumn()) {
            $query->where('company_id', $companyId);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'ilike', "%{$search}%")
                    ->orWhere('dni', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        $rows = $paginator->getCollection()->map(function (CollectionClient $client) use ($companyId) {
            $meta = $this->hasClientMetadataColumn() ? ($client->metadata ?? []) : [];
            $latestCredit = CollectionCredit::query()
                ->where('client_id', $client->id)
                ->when($this->hasCreditCompanyColumn(), function ($creditQuery) use ($companyId) {
                    $creditQuery->where('company_id', $companyId);
                })
                ->orderByDesc('id')
                ->first();

            $creditMeta = is_array($latestCredit?->metadata) ? $latestCredit->metadata : [];

            return [
                'id' => $client->id,
                'uuid' => (string) $client->id,
                'name' => $client->name,
                'dni' => $client->dni,
                'phone' => $client->phone,
                'email' => $meta['email'] ?? null,
                'address' => $client->address,
                'reference' => $meta['reference'] ?? null,
                'company_name' => $meta['company_name'] ?? null,
                'status' => $meta['status'] ?? 'Activo',
                'profile_photo' => $meta['profile_photo'] ?? null,
                'document_photo' => $meta['document_photo'] ?? null,
                'credit_id' => $latestCredit?->id,
                'credit_amount' => $latestCredit?->amount,
                'credit_interest_rate' => $latestCredit?->interest_rate,
                'credit_total_installments' => $latestCredit?->total_installments,
                'credit_payment_frequency' => $latestCredit?->payment_frequency,
                'credit_first_installment_date' => $latestCredit?->first_installment_date?->toDateString(),
                'credit_status' => $latestCredit?->status,
                'transfer_voucher_photo' => $creditMeta['transfer_voucher_photo'] ?? null,
                'transfer_support_photo' => $creditMeta['transfer_support_photo'] ?? null,
                'transfer_bank_name' => $creditMeta['transfer_bank_name'] ?? null,
                'transfer_reference_number' => $creditMeta['transfer_reference_number'] ?? null,
                'created_at' => optional($client->created_at)->toISOString(),
            ];
        })->values();

        return $this->successResponse([
            'success' => true,
            'data' => [
                'data' => $rows,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    public function create(array $payload)
    {
        $companyId = $this->resolveCompanyId($payload['company_id'] ?? null);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $dni = trim((string) ($payload['dni'] ?? ''));
        $existsDniQuery = CollectionClient::query()->where('dni', $dni);
        if ($this->hasClientCompanyColumn()) {
            $existsDniQuery->where('company_id', $companyId);
        }
        $existsDni = $existsDniQuery->exists();

        if ($existsDni) {
            return $this->errorResponse('El documento ya existe en Collection', 422);
        }

        return DB::connection(self::CONNECTION)->transaction(function () use ($payload, $companyId, $dni) {
            $this->partitionService->ensurePartitions($companyId);

            // id generado por la secuencia de PostgreSQL.
            $metadata = [
                'email' => $payload['email'] ?? null,
                'reference' => $payload['reference'] ?? null,
                'company_name' => $payload['company_name'] ?? null,
                'profile_photo' => $payload['profile_photo'] ?? null,
                'document_photo' => $payload['document_photo'] ?? null,
                'status' => 'Activo',
            ];

            $createData = [
                'dni' => $dni,
                'name' => trim((string) ($payload['name'] ?? '')),
                'phone' => trim((string) ($payload['phone'] ?? '')),
                'address' => trim((string) ($payload['address'] ?? '')),
            ];

            if ($this->hasClientCompanyColumn()) {
                $createData['company_id'] = $companyId;
            }

            if ($this->hasClientMetadataColumn()) {
                $createData['metadata'] = $metadata;
            }

            if ($this->hasClientIsActiveColumn()) {
                $createData['is_active'] = true;
            }

            $client = CollectionClient::query()->create($createData);

            $this->audit($companyId, $client->id, 'created', [
                'new' => [
                    'name' => $client->name,
                    'dni' => $client->dni,
                ],
            ]);

            return $this->successCreatedResponse([
                'success' => true,
                'message' => 'Cliente Collection creado',
                'data' => [
                    'id' => $client->id,
                    'uuid' => (string) $client->id,
                ],
            ]);
        });
    }

    public function update(int $clientId, array $payload)
    {
        $companyId = $this->resolveCompanyId($payload['company_id'] ?? null);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $client = CollectionClient::query()
            ->where('id', $clientId)
            ->first();

        if ($client && $this->hasClientCompanyColumn() && (int) $client->company_id !== $companyId) {
            $client = null;
        }

        if (!$client) {
            return $this->errorNotFoundResponse('Cliente Collection no encontrado');
        }

        $incomingDni = trim((string) ($payload['dni'] ?? $client->dni));
        $duplicateDniQuery = CollectionClient::query()
            ->where('dni', $incomingDni)
            ->where('id', '!=', $clientId);
        if ($this->hasClientCompanyColumn()) {
            $duplicateDniQuery->where('company_id', $companyId);
        }
        $duplicateDni = $duplicateDniQuery->exists();

        if ($duplicateDni) {
            return $this->errorResponse('El documento ya existe en Collection', 422);
        }

        return DB::connection(self::CONNECTION)->transaction(function () use ($client, $payload, $incomingDni, $companyId) {
            $hasMetadataColumn = $this->hasClientMetadataColumn();
            $oldSnapshot = [
                'name' => $client->name,
                'dni' => $client->dni,
                'phone' => $client->phone,
                'address' => $client->address,
                'metadata' => $hasMetadataColumn ? ($client->metadata ?? []) : [],
            ];

            $metadata = [];
            if ($hasMetadataColumn) {
                $metadata = $client->metadata ?? [];
                $metadata['email'] = $payload['email'] ?? ($metadata['email'] ?? null);
                $metadata['reference'] = $payload['reference'] ?? ($metadata['reference'] ?? null);
                $metadata['company_name'] = $payload['company_name'] ?? ($metadata['company_name'] ?? null);
                $metadata['profile_photo'] = $payload['profile_photo'] ?? ($metadata['profile_photo'] ?? null);
                $metadata['document_photo'] = $payload['document_photo'] ?? ($metadata['document_photo'] ?? null);
                $metadata['status'] = $metadata['status'] ?? 'Activo';
            }

            $updateData = [
                'name' => trim((string) ($payload['name'] ?? $client->name)),
                'dni' => $incomingDni,
                'phone' => trim((string) ($payload['phone'] ?? $client->phone)),
                'address' => trim((string) ($payload['address'] ?? $client->address)),
            ];

            if ($hasMetadataColumn) {
                $updateData['metadata'] = $metadata;
            }

            $client->fill($updateData);
            $client->save();

            $this->audit($companyId, $client->id, 'updated', [
                'old' => $oldSnapshot,
                'new' => [
                    'name' => $client->name,
                    'dni' => $client->dni,
                    'phone' => $client->phone,
                    'address' => $client->address,
                    'metadata' => $hasMetadataColumn ? $metadata : [],
                ],
            ]);

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente Collection actualizado',
                'data' => [
                    'id' => $client->id,
                    'uuid' => (string) $client->id,
                ],
            ]);
        });
    }

    public function get(int $clientId, ?int $requestedCompanyId = null, ?int $requestedCreditId = null)
    {
        $companyId = $this->resolveCompanyId($requestedCompanyId);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $client = CollectionClient::query()
            ->where('id', $clientId)
            ->first();

        if ($client && $this->hasClientCompanyColumn() && (int) $client->company_id !== $companyId) {
            $client = null;
        }

        if (!$client) {
            return $this->errorNotFoundResponse('Cliente Collection no encontrado');
        }

        $meta = $this->hasClientMetadataColumn() ? ($client->metadata ?? []) : [];
        $allCredits = CollectionCredit::query()
            ->where('client_id', $client->id)
            ->when($this->hasCreditCompanyColumn(), function ($creditQuery) use ($companyId) {
                $creditQuery->where('company_id', $companyId);
            })
            ->orderByDesc('id')
            ->get();

        $creditsData = $allCredits->map(function ($credit) use ($companyId) {
            $meta = is_array($credit->metadata) ? $credit->metadata : [];
            
            // Calculate balance for each credit (Total Principal Remaining + Pending Interest)
            $stats = CollectionInstallment::query()
                ->where('company_id', $companyId)
                ->where('credit_id', $credit->id)
                ->whereNull('deleted_at')
                ->selectRaw('
                    SUM(COALESCE(principal_paid, 0)) as total_principal_paid,
                    SUM(COALESCE(interest_amount, 0) - COALESCE(interest_paid, 0)) as pending_interest,
                    SUM(COALESCE(paid_amount, 0)) as total_paid_all
                ')
                ->first();
                
            $remainingPrincipal = max(0, (float)($credit->amount) - (float)($stats->total_principal_paid ?? 0));
            $pendingInterest = max(0, (float)($stats->pending_interest ?? 0));
            $realBalance = $remainingPrincipal + $pendingInterest;

            return [
                'id' => $credit->id,
                'amount' => (float) $credit->amount,
                'interest_rate' => (float) $credit->interest_rate,
                'total_installments' => $credit->total_installments,
                'payment_frequency' => $credit->payment_frequency,
                'first_installment_date' => $credit->first_installment_date?->toDateString(),
                'status' => $credit->status,
                'balance' => $realBalance,
                'total_paid' => (float) ($stats->total_paid_all ?? 0),
                'total_principal_paid' => (float) ($stats->total_principal_paid ?? 0),
                'total_interest_paid' => (float) ($stats->total_paid_all ?? 0) - (float) ($stats->total_principal_paid ?? 0),
                'created_at' => optional($credit->created_at)->toISOString(),
                'transfer_bank_name' => $meta['transfer_bank_name'] ?? null,
                'transfer_reference_number' => $meta['transfer_reference_number'] ?? null,
            ];
        });

        $latestCredit = $requestedCreditId 
            ? $allCredits->firstWhere('id', $requestedCreditId) 
            : $allCredits->first();

        $installments = [];
        
        if ($latestCredit) {
            $existingInstallmentsCount = CollectionInstallment::query()
                ->where('company_id', $companyId)
                ->where('credit_id', $latestCredit->id)
                ->count();

            if ($existingInstallmentsCount === 0 && $latestCredit->total_installments > 0) {
                // Fetch the actual model to get metadata correctly for generation
                $creditModel = CollectionCredit::find($latestCredit->id);
                $creditMeta = is_array($creditModel->metadata) ? $creditModel->metadata : [];
                $this->creditService->generateInstallments($creditModel, $creditMeta['excluded_days'] ?? []);
            }

            $installments = CollectionInstallment::query()
                ->where('company_id', $companyId)
                ->where('credit_id', $latestCredit->id)
                ->with(['payments' => function ($q) use ($companyId, $latestCredit) {
                    $q->where('company_id', $companyId)
                      ->where('credit_id', $latestCredit->id)
                      ->orderBy('recorded_at', 'asc');
                }])
                ->orderBy('installment_number')
                ->get()
                ->map(function ($inst) {
                    $deletingUser = $inst->deleted_by ? \App\Models\User::find($inst->deleted_by) : null;
                    return [
                        'id' => $inst->id,
                        'installment_number' => $inst->installment_number,
                        'due_date' => $inst->due_date?->toDateString(),
                        'amount' => (float) $inst->amount,
                        'principal_amount' => (float) ($inst->principal_amount ?? ($inst->amount)),
                        'interest_amount' => (float) ($inst->interest_amount ?? 0),
                        'paid_amount' => (float) $inst->paid_amount,
                        'principal_paid' => (float) ($inst->principal_paid ?? 0),
                        'interest_paid' => (float) ($inst->interest_paid ?? 0),
                        'status' => $inst->status,
                        'last_payment_at' => $inst->last_payment_at?->toISOString(),
                        'payment_method' => $inst->payment_method,
                        'notes' => $inst->notes,
                        'voucher_path' => $inst->voucher_path,
                        'history' => $inst->history,
                        'deleted_at' => $inst->deleted_at?->toISOString(),
                        'deleted_by_name' => $deletingUser ? $deletingUser->name : null,
                        'payments' => $inst->payments->map(function($p) {
                            return [
                                'id' => $p->id,
                                'amount_paid' => (float) $p->amount_paid,
                                'interest_paid' => (float) ($p->interest_paid ?? 0),
                                'principal_paid' => (float) ($p->principal_paid ?? 0),
                                'payment_date' => $p->payment_date?->toDateString(),
                                'payment_method' => $p->payment_method,
                                'notes' => $p->notes,
                                'voucher_path' => $p->voucher_path,
                                'recorded_at' => optional($p->recorded_at)->toISOString(),
                            ];
                        }),
                    ];
                });
        }

        $data = [
            'id' => $client->id,
            'uuid' => (string) $client->id,
            'name' => $client->name,
            'dni' => $client->dni,
            'phone' => $client->phone,
            'email' => $meta['email'] ?? null,
            'address' => $client->address,
            'reference' => $meta['reference'] ?? null,
            'company_name' => $meta['company_name'] ?? null,
            'status' => $meta['status'] ?? 'Activo',
            'profile_photo' => $meta['profile_photo'] ?? null,
            'document_photo' => $meta['document_photo'] ?? null,
            'credits' => $creditsData,
            // Keep keys for compatibility with single-credit UI if needed
            'credit_id' => $latestCredit?->id,
            'credit_amount' => $latestCredit?->amount,
            'credit_status' => $latestCredit?->status,
            'installments' => $installments,
            'created_at' => optional($client->created_at)->toISOString(),
        ];

        return $this->successResponse([
            'success' => true,
            'data' => $data
        ]);
    }

    public function delete(int $clientId, ?int $requestedCompanyId = null)
    {
        $companyId = $this->resolveCompanyId($requestedCompanyId);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $client = CollectionClient::query()
            ->where('id', $clientId)
            ->first();

        if ($client && $this->hasClientCompanyColumn() && (int) $client->company_id !== $companyId) {
            $client = null;
        }

        if (!$client) {
            return $this->errorNotFoundResponse('Cliente Collection no encontrado');
        }

        $hasActiveCredit = CollectionCredit::query()
            ->where('client_id', $client->id)
            ->whereIn(DB::raw('LOWER(status)'), ['activo', 'active', 'vigente'])
            ->when($this->hasCreditCompanyColumn(), function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->exists();

        if ($hasActiveCredit) {
            return $this->errorResponse('No se puede eliminar: el cliente tiene crédito activo en Collection', 422);
        }

        return DB::connection(self::CONNECTION)->transaction(function () use ($client, $companyId) {
            $this->audit($companyId, $client->id, 'deleted', [
                'old' => [
                    'name' => $client->name,
                    'dni' => $client->dni,
                    'phone' => $client->phone,
                    'address' => $client->address,
                    'metadata' => $client->metadata ?? [],
                ],
            ]);

            $client->delete();

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente Collection eliminado',
            ]);
        });
    }

    private function resolveCompanyId($requestedCompanyId): ?int
    {
        if (!empty($requestedCompanyId)) {
            return (int) $requestedCompanyId;
        }

        $user = Auth::user();
        if (!$user) {
            return null;
        }

        if ($user->company && !empty($user->company->id)) {
            return (int) $user->company->id;
        }

        if ($user->seller && !empty($user->seller->company_id)) {
            return (int) $user->seller->company_id;
        }

        return null;
    }

    private function audit(int $companyId, int $clientId, string $action, array $changes = []): void
    {
        CollectionClientAudit::query()->create([
            'company_id' => $companyId,
            'client_id' => $clientId,
            'action' => $action,
            'user_id' => Auth::id(),
            'ip_address' => request()->ip(),
            'changes' => $changes,
        ]);
    }

    private function hasClientCompanyColumn(): bool
    {
        return Schema::connection(self::CONNECTION)->hasColumn('collection_clients', 'company_id');
    }

    private function hasCreditCompanyColumn(): bool
    {
        return Schema::connection(self::CONNECTION)->hasColumn('collection_credits', 'company_id');
    }

    private function hasClientMetadataColumn(): bool
    {
        return Schema::connection(self::CONNECTION)->hasColumn('collection_clients', 'metadata');
    }

    private function hasClientIsActiveColumn(): bool
    {
        return Schema::connection(self::CONNECTION)->hasColumn('collection_clients', 'is_active');
    }
}
