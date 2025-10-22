<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Helpers\Helper;
use App\Http\Requests\Client\ClientRequest;
use App\Models\Credit;
use Illuminate\Support\Facades\Storage;
use App\Models\Guarantor;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\Seller;
use App\Models\SellerConfig;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ClientService
{
    use ApiResponse;

    private const TIMEZONE = 'America/Caracas';
    private const MAX_PROFILE_IMAGES = 1;
    private const MAX_GALLERY_IMAGES = 4;
    private const MAX_IMAGE_SIZE_BYTES = 2097152;
    private LiquidationService $liquidationService;

    public function __construct(LiquidationService $liquidationService)
    {
        $this->liquidationService = $liquidationService;
    }


    public function create(ClientRequest $request)
    {
        try {
            $params = $request->validated();

            // Restricción por monto total de ventas nuevas en el día
            if (!empty($params['credit_value']) && (float)$params['credit_value'] > 0) {
                $sellerConfig = \App\Models\SellerConfig::where('seller_id', $params['seller_id'])->first();
                $limit = $sellerConfig ? floatval($sellerConfig->restrict_new_sales_amount ?? 0) : 0;
                if ($limit > 0) {
                    $today = \Carbon\Carbon::now('America/Caracas')->toDateString();
                    $newCreditsAmount = \App\Models\Credit::where('seller_id', $params['seller_id'])
                        ->whereDate('created_at', $today)
                        ->sum('credit_value');
                    $totalWithNew = $newCreditsAmount + floatval($params['credit_value']);
                    if ($totalWithNew > $limit) {
                        return $this->errorResponse('No puedes crear el crédito. El monto total de ventas nuevas por el cobrador hoy supera el límite de $' . number_format($limit, 2), 403);
                    }
                }
            }

            if ($request->has('images')) {
                $validation = $this->validateImages($request);
                if ($validation !== true) {
                    return $validation;
                }
            }

            return DB::transaction(function () use ($params, $request) {
                $guarantorId = null;
                if (!empty($params['guarantor_name'])) {
                    $guarantor = Guarantor::create([
                        'name' => $params['guarantor_name'],
                        'dni' => $params['guarantor_dni'] ?? null,
                        'address' => $params['guarantor_address'] ?? null,
                        'phone' => $params['guarantor_phone'] ?? null,
                        'email' => $params['guarantor_email'] ?? null,
                    ]);
                    $guarantorId = $guarantor->id;
                }

                $client = Client::create([
                    'name' => $params['name'],
                    'dni' => $params['dni'],
                    'address' => $params['address'] ?? null,
                    'geolocation' => $params['geolocation'] ?? null,
                    'phone' => $params['phone'] ?? null,
                    'email' => $params['email'] ?? null,
                    'company_name' => $params['company_name'] ?? null,
                    'guarantor_id' => $guarantorId,
                    'seller_id' => $params['seller_id'] ?? null,
                    'routing_order' => $params['routing_order'] ?? null,
                ]);

                // Optional initial credit creation
                if (!empty($params['credit_value']) && (float)$params['credit_value'] > 0) {
                    $this->createCreditForNewClient($client, $params, $guarantorId);
                }

                // images
                if ($request->has('images')) {
                    $this->storeClientImages($client, $request);
                }

                return $this->successResponse([
                    'success' => true,
                    'message' => 'Cliente creado con éxito',
                    'data' => $client->fresh(),
                ]);
            }, 5);
        } catch (Throwable $e) {
            Log::error("Error al crear cliente: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al crear el cliente: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create credit (extracted) and generate its installments.
     */
    private function createCreditForNewClient(Client $client, array $params, ?int $guarantorId = null): Credit
    {
        $paymentFrequency = $params['payment_frequency'] ?? 'Mensual';
        $isAdvance = $params['is_advance_payment'] ?? false;
        $excludedDays = $params['excluded_days'] ?? [];

        // Determine first quota date
        $firstQuotaDate = $params['first_installment_date'] ?? null;
        if (!$firstQuotaDate) {
            $today = Carbon::now(self::TIMEZONE);
            if ($isAdvance) {
                $firstQuotaDate = $today->format('Y-m-d');
            } else {
                switch ($paymentFrequency) {
                    case 'Diaria':
                        $firstQuotaDate = $today->addDay()->format('Y-m-d');
                        break;
                    case 'Semanal':
                        $firstQuotaDate = $today->addWeek()->format('Y-m-d');
                        break;
                    case 'Quincenal':
                        $firstQuotaDate = $today->addDays(15)->format('Y-m-d');
                        break;
                    case 'Mensual':
                    default:
                        $firstQuotaDate = $today->addMonth()->format('Y-m-d');
                        break;
                }
            }
        }

        $credit = Credit::create([
            'client_id' => $client->id,
            'guarantor_id' => $guarantorId,
            'seller_id' => $params['seller_id'] ?? $client->seller_id,
            'credit_value' => $params['credit_value'],
            'total_interest' => $params['interest_rate'] ?? 0,
            'number_installments' => $params['installment_count'] ?? 1,
            'payment_frequency' => $paymentFrequency,
            'first_quota_date' => $firstQuotaDate,
            'excluded_days' => json_encode($excludedDays),
            'micro_insurance_percentage' => $params['micro_insurance_percentage'] ?? null,
            'micro_insurance_amount' => $params['micro_insurance_amount'] ?? null,
            'is_advance_payment' => $isAdvance,
            'status' => 'Vigente'
        ]);

       

        $quotaAmount = (($credit->credit_value * $credit->total_interest / 100) + $credit->credit_value) / max(1, $credit->number_installments);
        $this->generateInstallmentsForCredit($credit, (float)$quotaAmount);

        return $credit;
    }

    private function generateInstallmentsForCredit(Credit $credit, float $quotaAmount): void
    {
        $excludedDayNames = json_decode($credit->excluded_days ?? '[]', true) ?: [];

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
        foreach ($excludedDayNames as $day) {
            if (isset($dayMap[$day])) $excludedDayNumbers[] = $dayMap[$day];
        }

        $adjust = function (Carbon $date) use ($excludedDayNumbers) {
            while (in_array($date->dayOfWeek, $excludedDayNumbers, true)) {
                $date->addDay();
            }
            return $date;
        };

        $dueDate = $adjust(Carbon::parse($credit->first_quota_date, self::TIMEZONE));

        for ($i = 1; $i <= $credit->number_installments; $i++) {
            Installment::create([
                'credit_id' => $credit->id,
                'quota_number' => $i,
                'due_date' => $dueDate->format('Y-m-d'),
                'quota_amount' => round($quotaAmount, 2),
                'status' => 'Pendiente'
            ]);

            if ($i < $credit->number_installments) {
                switch ($credit->payment_frequency) {
                    case 'Diaria':
                        $dueDate->addDay();
                        break;
                    case 'Semanal':
                        $dueDate->addWeek();
                        break;
                    case 'Quincenal':
                        $dueDate->addDays(15);
                        break;
                    case 'Mensual':
                    default:
                        $dueDate->addMonth();
                        break;
                }
                $dueDate = $adjust($dueDate);
            }
        }
    }



    public function update(ClientRequest $request, $clientId)
    {
        DB::beginTransaction();
        try {
            $client = Client::find($clientId);
            if (!$client) {
                DB::rollBack();
                return $this->errorNotFoundResponse('Cliente no encontrado');
            }

            $params = $request->validated();
            $client->update($params);

            if ($request->has('images')) {
                $validation = $this->validateImages($request);
                if ($validation !== true) {
                    DB::rollBack();
                    return $validation;
                }

                $images = $request->input('images');
                foreach ($images as $index => $imageData) {
                    $imageFile = $request->file("images.{$index}.file");
                    $imageType = $imageData['type'] ?? 'gallery';

                    $existingImage = $client->images()->where('type', $imageType)->first();
                    if ($existingImage) {
                        Helper::deleteFile($existingImage->path);
                        $existingImage->delete();
                    }

                    $path = Helper::uploadFile($imageFile, 'clients');
                    $client->images()->create(['path' => $path, 'type' => $imageType]);
                }
            }

            if ($request->has('guarantors_ids')) {
                $client->guarantors()->sync($request->input('guarantors_ids'));
            }

            DB::commit();
            return $this->successResponse(['success' => true, 'message' => 'Cliente actualizado con éxito', 'data' => $client->fresh()]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Error al actualizar cliente {$clientId}: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al actualizar el cliente', 500);
        }
    }

    private function validateImages($request)
    {
        $images = $request->all()['images'] ?? [];
        $profileCount = 0;
        $galleryCount = 0;

        foreach ($images as $index => $imageData) {
            if (!isset($imageData['file']) || !isset($imageData['type'])) {
                return $this->errorResponse('Cada imagen debe contener un archivo y un tipo ("profile" o "gallery").', 400);
            }

            $type = $imageData['type'];

            if ($type === 'profile') $profileCount++;
            elseif ($type === 'gallery') $galleryCount++;

            if ($profileCount > self::MAX_PROFILE_IMAGES) {
                return $this->errorResponse('Solo se permite una foto de perfil.', 400);
            }
            if ($galleryCount > self::MAX_GALLERY_IMAGES) {
                return $this->errorResponse('Solo se permiten hasta 4 imágenes en la galería.', 400);
            }

            $imageFile = $request->file("images.{$index}.file");
            if (!$imageFile) {
                return $this->errorResponse("No se encontró la imagen {$index} en la solicitud.", 400);
            }

            if ($imageFile->getSize() > self::MAX_IMAGE_SIZE_BYTES) {
                return $this->errorResponse("La imagen {$index} excede 2MB", 400);
            }

            $allowed = ['profile', 'gallery', 'money_in_hand', 'business', 'document'];
            if (!in_array($type, $allowed, true)) {
                return $this->errorResponse('Tipo de imagen inválido.', 400);
            }
        }

        return true;
    }

    private function storeClientImages(Client $client, Request $request): void
    {
        $images = $request->input('images', []);
        foreach ($images as $index => $imageData) {
            $imageFile = $request->file("images.{$index}.file");
            $imageType = $imageData['type'] ?? 'gallery';

            if ($imageType === 'profile') {
                $existing = $client->images()->where('type', 'profile')->first();
                if ($existing) {
                    Helper::deleteFile($existing->path);
                    $existing->delete();
                }
            }

            $path = Helper::uploadFile($imageFile, 'clients');
            $client->images()->create(['path' => $path, 'type' => $imageType]);
        }
    }


    public function delete($clientId)
    {
        try {
            $client = Client::find($clientId);
            if (!$client) return $this->errorNotFoundResponse('Cliente no encontrado');

            if ($client->credits()->where('status', 'Vigente')->exists()) {
                return $this->errorResponse(['No se puede eliminar el cliente con créditos vigentes'], 401);
            }

            DB::transaction(function () use ($client) {
                $client->guarantors()->detach();
                $client->images()->each(fn($image) => $image->delete());
                $client->delete();
            });

            return $this->successResponse(['success' => true, 'message' => "Cliente eliminado con éxito"]);
        } catch (Throwable $e) {
            Log::error("Error al eliminar cliente {$clientId}: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al eliminar el cliente', 500);
        }
    }


    public function index(
        $search = '',
        $orderBy = 'created_at',
        $orderDirection = 'desc',
        $countryId = null,
        $cityId = null,
        $sellerId = null,
        $status = null
    ) {
        try {
            $search = (string)$search;
            $user = Auth::user();
            $seller = $user->seller;
            $company = $user->company;

            $clientsQuery = Client::query()
                ->select('id', 'name', 'dni', 'email', 'status', 'seller_id', 'geolocation', 'routing_order', 'capacity')
                // eager load seller basic columns and seller.user for name
                ->with([
                    'seller' => function ($q) {
                        $q->select('id', 'user_id', 'city_id', 'company_id');
                    },
                    'seller.user' => function ($q) {
                        $q->select('id', 'name');
                    },
                    'seller.city' => function ($q) {
                        $q->select('id', 'name', 'country_id');
                    },
                    'seller.city.country' => function ($q) {
                        $q->select('id', 'name');
                    },
                    // For credits we only retrieve minimal data + payments sum and pending installments count
                    'credits' => function ($q) {
                        $q->select('id', 'client_id', 'credit_value', 'number_installments', 'payment_frequency', 'status', 'total_interest')
                            ->withSum('payments', 'amount')
                            ->withCount(['installments as pending_installments_count' => function ($iq) {
                                $iq->where('status', '<>', 'Pagado');
                            }])
                            ->withMax('installments', 'due_date'); // final date approximation
                    },
                    'credits.installments' => function ($q) {
                        $q->select('id', 'credit_id', 'quota_number', 'due_date', 'quota_amount', 'status');
                    },
                ]);

            // Role scoping (unchanged behavior)
            switch ($user->role_id) {
                case 1:
                    break;
                case 2:
                    if ($company) {
                        $clientsQuery->whereHas('seller', fn($q) => $q->where('company_id', $company->id));
                    }
                    break;
                case 5:
                    if ($seller) $clientsQuery->where('seller_id', $seller->id);
                    else $clientsQuery->whereRaw('0 = 1');
                    break;
                default:
                    $clientsQuery->whereRaw('0 = 1');
                    break;
            }

            if (!empty(trim($search))) {
                $clientsQuery->where(fn($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('dni', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"));
            }

            if ($countryId) $clientsQuery->whereHas('seller.city.country', fn($q) => $q->where('id', $countryId));
            if ($cityId) $clientsQuery->whereHas('seller.city', fn($q) => $q->where('id', $cityId));
            if ($sellerId) $clientsQuery->where('seller_id', $sellerId);
            elseif ($user->role_id == 5 && $seller) $clientsQuery->where('seller_id', $seller->id);

            // status filters (kept as original)
            if ($status === 'Cartera Irrecuperable') {
                $clientsQuery->whereHas('credits', fn($q) => $q->where('status', $status));
                $clientsQuery->with(['credits' => fn($q) => $q->where('status', $status)]);
            } elseif ($status === 'Inactivo') {
                $clientsQuery->where('status', 'inactive');
            } elseif ($status === 'Activo' && in_array($user->role_id, [1, 2], true)) {
                $clientsQuery->where('status', 'active')
                    ->where(fn($q) => $q->whereDoesntHave('credits', fn($qq) => $qq->where('status', 'Cartera Irrecuperable'))
                        ->orWhereHas('credits', fn($qq) => $qq->whereIn('status', ['Activo', 'Vigente'])))
                    ->with(['credits' => fn($qq) => $qq->whereIn('status', ['Activo', 'Vigente'])]);
            } else {
                $clientsQuery->where('status', 'active');
            }

            $validOrderDirections = ['asc', 'desc'];
            $orderDirection = in_array(strtolower($orderDirection), $validOrderDirections) ? $orderDirection : 'desc';
            $clientsQuery->orderBy($orderBy, $orderDirection);

            // Execute query
            $clients = $clientsQuery->get();

            // Agregar campo total_credits_value a cada cliente
            $clients->transform(function ($client) {
                $totalCreditsValue = $client->credits
                    ->whereIn('status', ['Activo', 'Vigente'])
                    ->sum('credit_value');
                $client->total_credits_value = $totalCreditsValue;
                return $client;
            });

            return $this->successResponse([
                'success' => true,
                'message' => 'Clientes encontrados',
                'data' => $clients
            ]);
        } catch (Throwable $e) {
            Log::error("Error en index clientes: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al obtener los clientes', 500);
        }
    }

    public function indexWithCredits(
        $search = '',
        $orderBy = 'created_at',
        $orderDirection = 'desc',
        $countryId = null,
        $cityId = null,
        $sellerId = null,
        $status = null,
        $daysOverdueFilter = null
    ) {
        try {
            $search = (string)$search;
            $user = Auth::user();
            $seller = $user->seller;

            // Load clients with minimal necessary relations. We still load installments per credit because
            // the front expects quota_amount, due_date and uses them to compute days_overdue and final_date.
            $clientsQuery = Client::query()
                ->with([
                    'seller',
                    'seller.city',
                    'seller.city.country',
                    'credits' => function ($q) use ($status) {
                        if ($status === 'Cartera Irrecuperable') {
                            $q->where('status', 'Cartera Irrecuperable');
                        } else {
                            $q->where('status', 'Vigente');
                        }
                        // load minimal payment fields only for local use (we will pre-aggregate payments separately)
                        $q->with(['payments' => function ($pq) {
                            $pq->select('id', 'credit_id', 'amount', 'payment_date', 'created_at', 'payment_method', 'status');
                        }, 'installments' => function ($iq) {
                            $iq->select('id', 'credit_id', 'quota_number', 'due_date', 'quota_amount', 'status');
                        }]);
                    }
                ])
                ->select('clients.*');

            if (!empty(trim($search))) {
                $clientsQuery->where(function ($q) use ($search) {
                    $q->where('clients.name', 'like', "%{$search}%")
                        ->orWhere('clients.dni', 'like', "%{$search}%")
                        ->orWhere('clients.email', 'like', "%{$search}%");
                });
            }

            if ($countryId) $clientsQuery->whereHas('seller.city.country', fn($q) => $q->where('id', $countryId));
            if ($cityId) $clientsQuery->whereHas('seller.city', fn($q) => $q->where('id', $cityId));
            if ($sellerId) $clientsQuery->where('clients.seller_id', $sellerId);
            elseif ($user->role_id == 5 && $seller) $clientsQuery->where('clients.seller_id', $seller->id);

            $orderDirection = in_array(strtolower($orderDirection), ['asc', 'desc']) ? $orderDirection : 'desc';
            $clientsQuery->orderBy($orderBy, $orderDirection);

            $clients = $clientsQuery->get();

            // Pre-aggregate payments totals for all credits in one query
            $allCreditIds = $clients->flatMap(fn($c) => $c->credits->pluck('id'))->unique()->values()->all();
            $paymentsByCredit = $this->paymentsSumByCredit($allCreditIds); // [credit_id => sum]

            // Transform to the exact same shape your front expects
            $transformedClients = [];
            $nowLocal = Carbon::now(self::TIMEZONE);

            foreach ($clients as $client) {
                foreach ($client->credits as $credit) {
                    $totalAmount = ($credit->credit_value * $credit->total_interest / 100) + $credit->credit_value;
                    $paidAmount = (float)($paymentsByCredit[$credit->id] ?? 0.0);
                    $remainingAmount = $totalAmount - $paidAmount;

                    // last payment info (from eager loaded payments collection)
                    $lastPayment = $credit->payments->sortByDesc('payment_date')->first();
                    $lastPaymentDate = $lastPayment ? $lastPayment->payment_date : null;
                    $lastPaymentAmount = $lastPayment ? $lastPayment->amount : 0;

                    // days overdue: find earliest overdue installment
                    $overdueInstallments = $credit->installments->filter(fn($inst) => $inst->due_date < $nowLocal->format('Y-m-d') && $inst->status !== 'Pagado');

                    $daysOverdue = $overdueInstallments->count() > 0
                        ? (int) $nowLocal->diffInDays(Carbon::parse($overdueInstallments->sortBy('due_date')->first()->due_date, self::TIMEZONE))
                        : 0;

                    if ($daysOverdueFilter !== null && $daysOverdue != $daysOverdueFilter) {
                        continue;
                    }

                    $pendingInstallments = $credit->installments->filter(fn($inst) => $inst->status !== 'Pagado');
                    $pendingInstallmentsCount = $pendingInstallments->count();
                    $quotaAmount = $credit->installments->first() ? $credit->installments->first()->quota_amount : 0;
                    $finalDate = $credit->installments->sortByDesc('due_date')->first()->due_date ?? null;

                    $transformedClients[] = [
                        'id' => $client->id,
                        'name' => $client->name,
                        'dni' => $client->dni,
                        'address' => $client->address,
                        'credit' => [
                            'id' => $credit->id,
                            'credit_value' => $credit->credit_value,
                            'remaining_amount' => $remainingAmount,
                            'last_payment_amount' => $lastPaymentAmount,
                            'last_payment_date' => $lastPaymentDate,
                            'days_overdue' => $daysOverdue,
                            'quota_amount' => $quotaAmount,
                            'pending_installments' => $pendingInstallmentsCount,
                            'final_date' => $finalDate,
                            'status' => $credit->status,
                            'number_installments' => $credit->number_installments,
                        ]
                    ];
                }
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Clientes encontrados',
                'data' => $transformedClients
            ]);
        } catch (Throwable $e) {
            Log::error("Error en indexWithCredits: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al obtener los clientes', 500);
        }
    }

    private function paymentsSumByCredit(array $creditIds): array
    {
        if (empty($creditIds)) return [];
        $rows = Payment::whereIn('credit_id', $creditIds)
            ->select('credit_id', DB::raw('SUM(amount) as total'))
            ->groupBy('credit_id')
            ->get();

        $result = [];
        foreach ($rows as $r) $result[$r->credit_id] = (float)$r->total;
        return $result;
    }

    public function getClientsBySeller($sellerId, $search)
    {
        try {
            $search = trim((string)$search);

            // Seleccionar solo las columnas necesarias para el front y evitar cargar colecciones pesadas.
            // Para créditos pedimos sums y counts útiles para mostrar en UI sin cargar todos los pagos.
            $clients = Client::query()
                ->select('id', 'name', 'dni', 'email', 'address', 'seller_id', 'routing_order', 'geolocation', 'phone', 'capacity')
                ->with([
                    // Cargar seller básico y su usuario para mostrar el nombre del vendedor si hace falta
                    'seller' => function ($q) {
                        $q->select('id', 'user_id', 'city_id', 'company_id');
                    },
                    'seller.user' => function ($q) {
                        $q->select('id', 'name');
                    },
                    'seller.city' => function ($q) {
                        $q->select('id', 'name', 'country_id');
                    },
                    'seller.city.country' => function ($q) {
                        $q->select('id', 'name');
                    },
                    // Cargar créditos con agregados para evitar N+1 (payments_sum, pending installments)
                    'credits' => function ($q) {
                        $q->select('id', 'client_id', 'credit_value', 'number_installments', 'payment_frequency', 'status', 'total_interest')
                            ->withSum('payments', 'amount') // payments_sum_amount
                            ->withCount(['installments as pending_installments_count' => function ($iq) {
                                $iq->where('status', '<>', 'Pagado');
                            }])
                            ->withMax('installments', 'due_date');
                    },
                    'credits.installments' => function ($q) {
                        $q->select('id', 'credit_id', 'quota_number', 'due_date', 'quota_amount', 'status');
                    },
                ])
                ->where('seller_id', $sellerId)
                ->when($search !== '', function ($q) use ($search) {
                    $q->where(function ($sq) use ($search) {
                        $sq->where('name', 'like', "%{$search}%")
                            ->orWhere('dni', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->whereDoesntHave('credits', function ($q) {
                    $q->where('status', 'Cartera Irrecuperable');
                })
                ->orderBy('routing_order', 'asc')
                ->get();

            // Agregar campo total_credits_value a cada cliente
            $clients->transform(function ($client) {
                $totalCreditsValue = $client->credits
                    ->whereIn('status', ['Activo', 'Vigente'])
                    ->sum('credit_value');
                $client->total_credits_value = $totalCreditsValue;
                return $client;
            });

            return $clients;
        } catch (\Exception $e) {
            Log::error("getClientsBySeller error: {$e->getMessage()} | " . $e->getTraceAsString());
            throw $e;
        }
    }

    public function getAllClientsBySeller($sellerId, $search = '', $date = null)
    {
        try {
            $filterDate = $date ? Carbon::parse($date, self::TIMEZONE) : Carbon::now(self::TIMEZONE);
            $startUTC = $filterDate->copy()->startOfDay()->timezone('UTC');
            $endUTC = $filterDate->copy()->endOfDay()->timezone('UTC');


            $clients = Client::query()
                ->select('id', 'name', 'dni', 'address', 'seller_id', 'routing_order', 'coordinates')
                ->with([
                    'guarantors' => function ($q) {
                        $q->select('guarantors.id as id', 'guarantors.name', 'guarantors.dni', 'guarantors.phone');
                    },
                    'images:id,client_id,path,type',
                    'seller' => function ($q) {
                        $q->select('id', 'user_id', 'city_id');
                    },
                    'seller.user' => function ($q) {
                        $q->select('id', 'name');
                    },
                    'seller.city' => function ($q) {
                        $q->select('id', 'name', 'country_id');
                    },
                    // Credits: cargamos counts útiles y las cuotas (installments) mínimas
                    'credits' => function ($q) {
                        $q->select('id', 'client_id', 'credit_value', 'number_installments', 'payment_frequency', 'status', 'total_interest')
                            ->withCount([
                                'installments as paid_installments_count' => function ($iq) {
                                    $iq->where('status', 'Pagado');
                                }
                            ]);
                    },
                    // Cargamos las cuotas completas de cada crédito (necesarias para calcular pendientes, días de mora, final date)
                    'credits.installments' => function ($q) {
                        $q->select('id', 'credit_id', 'quota_number', 'due_date', 'quota_amount', 'status', 'created_at');
                    },
                    // Solo los pagos del día para cada crédito (esto evita cargar todo el histórico)
                    'credits.payments' => function ($q) use ($startUTC, $endUTC) {
                        $q->select('id', 'credit_id', 'amount', 'payment_date', 'created_at', 'payment_method', 'latitude', 'longitude', 'status')
                            ->whereBetween('created_at', [$startUTC, $endUTC]);
                    },
                    // incluir payment_installments + installments.installment para obtener cuotas afectadas por cada pago
                    'credits.payments.installments' => function ($q) {
                        $q->select('id', 'payment_id', 'installment_id', 'applied_amount');
                    },
                    'credits.payments.installments.installment' => function ($q) {
                        $q->select('id', 'credit_id', 'quota_number', 'due_date', 'quota_amount', 'status');
                    },
                ])
                ->where('seller_id', $sellerId)
                ->when(trim($search) !== '', function ($q) use ($search) {
                    $q->where(function ($sq) use ($search) {
                        $sq->where('name', 'like', "%{$search}%")
                            ->orWhere('dni', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->orderBy('routing_order', 'asc')
                ->get();

            // Ahora procesamos en memoria: solo payments del día fueron cargados por crédito
            $clients->each(function ($client) use ($filterDate, $startUTC, $endUTC) {
                $client->distantPayments = collect();
                $client->todayPayments = collect();

                foreach ($client->credits as $credit) {
                    $credit->paid_installments_count = $credit->paid_installments_count ?? 0;
                    $credit->pending_installments = max(0, ($credit->number_installments ?? 0) - ($credit->paid_installments_count ?? 0));

                    // Pagos del día (ya filtrados en eager load)
                    $datePayments = $credit->payments ?? collect();

                    foreach ($datePayments as $payment) {
                        // Mapear cuotas pagadas por el pago (si existen payment_installments cargados)
                        $paidQuotaNumbers = collect($payment->installments ?? [])
                            ->map(function ($pi) {
                                return optional($pi->installment)->quota_number ?? 'N/A';
                            })->filter()->values()->all();

                        $paidQuotaNumbersStr = !empty($paidQuotaNumbers) ? implode(', ', $paidQuotaNumbers) : 'N/A';

                        $paymentTime = $payment->payment_date instanceof \DateTimeInterface
                            ? $payment->payment_date->format('H:i:s')
                            : ($payment->payment_date ? Carbon::parse($payment->payment_date)->format('H:i:s') : null);

                        $paymentData = [
                            'client_id' => $client->id,
                            'client_name' => $client->name,
                            'credit_id' => $credit->id,
                            'payment_id' => $payment->id,
                            'amount' => $payment->amount,
                            'payment_date' => $payment->payment_date,
                            'payment_time' => $payment->created_at,
                            'installment' => $paidQuotaNumbersStr,
                            'installment_details' => $payment->installments ?? collect(),
                            'latitude' => $payment->latitude,
                            'longitude' => $payment->longitude,
                            'paid_installments' => $credit->paid_installments_count,
                            'pending_installments' => $credit->pending_installments,
                        ];

                        $client->todayPayments->push($paymentData);

                        // Distancia: asegúrate de que client->coordinates sea un array con keys latitude/longitude
                        if (!empty($client->coordinates) && isset($client->coordinates['latitude'], $client->coordinates['longitude'])) {
                            $clientLat = (float)$client->coordinates['latitude'];
                            $clientLon = (float)$client->coordinates['longitude'];

                            if ($payment->latitude && $payment->longitude) {
                                $distance = $this->calculateDistance(
                                    $clientLat,
                                    $clientLon,
                                    (float)$payment->latitude,
                                    (float)$payment->longitude
                                );

                                if ($distance > 10) {
                                    $paymentData['distance'] = $distance;
                                    $client->distantPayments->push($paymentData);
                                }
                            }
                        }
                    }
                }
            });

            return $clients;
        } catch (\Exception $e) {
            Log::error("getAllClientsBySeller error: {$e->getMessage()} | " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }


    public function totalClients()
    {
        try {
            $user = Auth::user();
            $seller = $user->seller;

            $clientsQuery = Client::query();

            if ($user->role_id == 5 && $seller) {
                $clientsQuery->where('seller_id', $seller->id);
            }

            $totalClients = $clientsQuery->count();

            return $this->successResponse([
                'success' => true,
                'message' => 'Total de clientes obtenido',
                'data' => $totalClients
            ]);
        } catch (\Exception $e) {
            Log::error("totalClients error: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al obtener el total de clientes', 500);
        }
    }

    public function reactivateClients($countryId = null, $cityId = null, $sellerId = null)
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();
            if (!in_array($user->role_id, [1, 2], true)) {
                return $this->errorResponse('No tiene permisos para realizar esta acción', 403);
            }

            $query = Client::withTrashed()->whereNotNull('deleted_at');
            if ($countryId) {
                $query->whereHas('seller.city.country', function ($q) use ($countryId) {
                    $q->where('id', $countryId);
                });
            }

            if ($cityId) {
                $query->whereHas('seller.city', function ($q) use ($cityId) {
                    $q->where('id', $cityId);
                });
            }

            if ($sellerId) {
                $query->where('seller_id', $sellerId);
            }

            $reactivatedCount = $query->restore();

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => "Se reactivaron {$reactivatedCount} clientes exitosamente",
                'data' => [
                    'reactivated_count' => $reactivatedCount
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error al reactivar clientes: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al reactivar los clientes', 500);
        }
    }

    public function deleteInactiveClientsWithoutCredits()
    {
        $deletedIds = [];
        $deletedCount = 0;

        Client::where('status', 'inactive')
            ->whereDoesntHave('credits', function ($query) {
                $query->where('status', 'Vigente');
            })
            ->orderBy('id')
            ->chunkById(200, function ($clients) use (&$deletedIds, &$deletedCount) {
                /** @var \Illuminate\Database\Eloquent\Collection $clients */
                foreach ($clients as $client) {
                    $client->delete();
                    $deletedIds[] = $client->id;
                    $deletedCount++;
                }
            });

        return [
            'deleted_count' => $deletedCount,
            'deleted_ids' => $deletedIds
        ];
    }

    public function deleteClientsByIds(array $clientIds)
    {
        try {
            // Find clients that are eligible to delete (no 'Vigente' credits)
            $deletableQuery = Client::whereIn('id', $clientIds)
                ->whereDoesntHave('credits', function ($q) {
                    $q->where('status', 'Vigente');
                });

            $deletableCount = $deletableQuery->count();

            if ($deletableCount === 0) {
                return $this->errorResponse('No se encontraron clientes con los IDs proporcionados o tienen créditos vigentes', 404);
            }

            $deletedIds = [];
            $deletedCount = 0;

            $deletableQuery->orderBy('id')->chunkById(200, function ($clients) use (&$deletedIds, &$deletedCount) {
                foreach ($clients as $client) {
                    $client->delete();
                    $deletedIds[] = $client->id;
                    $deletedCount++;
                }
            });

            return $this->successResponse([
                'success' => true,
                'message' => 'Clientes eliminados con éxito',
                'data' => [
                    'deleted_count' => $deletedCount,
                    'deleted_ids' => $deletedIds
                ]
            ]);
        } catch (Throwable $e) {
            Log::error("Error al eliminar clientes: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al eliminar los clientes', 500);
        }
    }


    public function getClientsSelect(string $search = '')
    {
        try {
            $user = Auth::user();
            $search = trim($search);

            $query = Client::query()
                ->select('id', 'name', 'seller_id');

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('dni', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // scope to seller for role 5
            if ($user->role_id == 5 && $user->seller) {
                $query->where('seller_id', $user->seller->id);
            }

            $clients = $query->orderBy('name', 'asc')->limit(100)->get();

            if ($clients->isEmpty()) {
                return $this->errorNotFoundResponse('No se encontraron clientes');
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Clientes encontrados',
                'data' => $clients
            ]);
        } catch (Throwable $e) {
            Log::error("getClientsSelect error: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al obtener los clientes', 500);
        }
    }

    public function show($clientId)
    {
        try {
            $client = Client::with([
                'guarantors' => function ($q) {
                    $q->select('guarantors.id as id', 'guarantors.name', 'guarantors.dni', 'guarantors.phone');
                },
                'images:id,client_id,path,type',
                'seller' => function ($q) {
                    $q->select('id', 'user_id', 'city_id');
                },
                'seller.city' => function ($q) {
                    $q->select('id', 'name', 'country_id');
                },
                'credits' => function ($query) {
                    $query->select('id', 'client_id', 'credit_value', 'number_installments', 'payment_frequency', 'status', 'total_interest', 'created_at')
                        ->orderBy('created_at', 'desc');
                },
                'credits.installments' => function ($query) {
                    $query->select('id', 'credit_id', 'quota_number', 'due_date', 'quota_amount', 'status');
                }
            ])->find($clientId);

            if (!$client) {
                return $this->errorNotFoundResponse('Cliente no encontrado');
            }



            // Calcular el total de créditos activos/vigentes
            $totalCreditsValue = $client->credits
                ->whereIn('status', ['Activo', 'Vigente'])
                ->sum('credit_value');
            $client->total_credits_value = $totalCreditsValue;

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente encontrado',
                'data' => $client
            ]);
        } catch (Throwable $e) {
            Log::error("show client error: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al obtener el cliente', 500);
        }
    }



    public function getClientDetails($clientId)
    {
        try {
            $client = Client::with([
                'credits' => function ($cq) {
                    $cq->select('id', 'client_id', 'credit_value', 'total_interest', 'number_installments', 'status', 'created_at', 'payment_frequency')
                        ->with(['payments' => function ($pq) {
                            $pq->select('id', 'credit_id', 'amount', 'payment_date', 'created_at', 'payment_method', 'status');
                        }]);
                },
                'credits.payments.installments' => function ($q) {
                    $q->select('id', 'payment_id', 'installment_id', 'applied_amount');
                },
                'credits.payments.installments.installment' => function ($q) {
                    $q->select('id', 'credit_id', 'quota_number', 'due_date', 'quota_amount', 'status');
                },
                'seller' => function ($q) {
                    $q->select('id', 'user_id', 'city_id');
                },
                'seller.city' => function ($q) {
                    $q->select('id', 'name');
                },
                'guarantors' => function ($q) {
                    $q->select('guarantors.id as id', 'guarantors.name', 'guarantors.dni', 'guarantors.phone');
                },
                'images' => function ($q) {
                    $q->select('id', 'client_id', 'path', 'type');
                }
            ])->find($clientId);

            if (!$client) {
                return [
                    'success' => false,
                    'message' => 'Cliente no encontrado',
                    'data' => null
                ];
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente encontrado',
                'data' => $client
            ]);
        } catch (Throwable $e) {
            Log::error("getClientDetails error: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al obtener el cliente', 500);
        }
    }


    public function getForCollections(
        string $search = '',
        int $perpage = 10,
        int $page = 1,
        string $filter = 'all',
        string $frequency = '',
        string $paymentStatus = '',
        string $orderBy = 'created_at',
        string $orderDirection = 'desc'
    ) {
        try {
            $user = Auth::user();
            $seller = $user->seller;
            $timezone = self::TIMEZONE ?? 'America/Caracas';

            // Use timezone-aware "today"
            $todayLocal = Carbon::now($timezone)->toDateString();

            // Subquery to compute payment priority per client (overdue -> pending -> normal)
            $paymentPrioritySubquery = DB::table('clients')
                ->leftJoin('credits', function ($join) {
                    $join->on('clients.id', '=', 'credits.client_id')
                        ->where('credits.status', '!=', 'liquidado');
                })
                ->leftJoin('installments', function ($join) {
                    $join->on('credits.id', '=', 'installments.credit_id');
                })
                ->selectRaw('
                    clients.id as client_id,
                    MAX(CASE WHEN installments.due_date < CURDATE() THEN 1 ELSE 0 END) as has_overdue,
                    MAX(CASE WHEN installments.due_date >= CURDATE() THEN 1 ELSE 0 END) as has_pending
                ')
                ->groupBy('clients.id');

            // Base credits query
            $creditsQuery = Credit::query()
                ->select('credits.*')
                ->join('clients', 'clients.id', '=', 'credits.client_id')
                // keep same payment_priority selection as before for ordering/priority
                ->selectSub('
                    CASE 
                        WHEN payment_priority.has_overdue = 1 THEN 1
                        WHEN payment_priority.has_pending = 1 THEN 2
                        ELSE 3
                    END', 'payment_priority')
                ->leftJoinSub($paymentPrioritySubquery, 'payment_priority', function ($join) {
                    $join->on('clients.id', '=', 'payment_priority.client_id');
                })
                // Eager load only required relationships and columns
                ->with([
                    'client.guarantors',
                    'client.images',
                    'client.seller',
                    'client.seller.city',
                    'installments',
                    'payments',
                    'payments.installments'
                ])
                ->where(function ($query) {
                    $query->whereNotIn('credits.status', ['Liquidado', 'Unificado', 'Cartera Irrecuperable', 'Renovado'])
                        ->orWhere(function ($q) {
                            $q->whereIn('credits.status', ['Liquidado', 'Renovado'])
                                ->whereDate('credits.updated_at', now()->toDateString());
                        });
                })
                ->where(function ($query) use ($todayLocal) {
                    $query->where(function ($q) use ($todayLocal) {
                        $q->whereDate('credits.first_quota_date', $todayLocal)
                            ->whereDate('credits.created_at', '<=', $todayLocal);
                    })
                        ->orWhere(function ($q) use ($todayLocal) {
                            $q->whereDate('credits.first_quota_date', '<', $todayLocal);
                        })
                        ->orWhere(function ($q) use ($todayLocal) {
                            $q->whereDate('credits.created_at', '<', $todayLocal)
                                ->whereDate('credits.first_quota_date', '>', $todayLocal);
                        });
                });

            // Apply filters (same behavior)
            if (!empty($frequency)) {
                $creditsQuery->where('payment_frequency', $frequency);
            }

            if (!empty($paymentStatus)) {
                $todayLocal = Carbon::now($timezone)->toDateString();
                if ($paymentStatus === 'paid') {
                    $creditsQuery->whereHas('payments', function ($query) use ($todayLocal) {
                        $query->whereDate('created_at', $todayLocal)
                            ->whereIn('status', ['Pagado', 'Abonado']);
                    });
                } elseif ($paymentStatus === 'unpaid') {
                    $creditsQuery->whereDoesntHave('payments', function ($q) use ($todayLocal) {
                        $q->whereDate('created_at', $todayLocal);
                    });
                } elseif ($paymentStatus === 'notpaid') {
                    $creditsQuery->whereHas('payments', function ($query) use ($todayLocal) {
                        $query->whereDate('created_at', $todayLocal)
                            ->where('status', 'No pagado');
                    });
                }
            }

            if (!empty($search)) {
                $creditsQuery->whereHas('client', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('dni', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($filter !== 'all') {
                $creditsQuery->where('credits.status', $filter);
            }

            if ($user->role_id == 5 && $seller) {
                $creditsQuery->whereHas('client', function ($q) use ($seller) {
                    $q->where('seller_id', $seller->id);
                });
            }

            // ordering (keep same as original)
            $creditsQuery->orderBy('clients.routing_order', 'asc');

            // Execute query: get credits
            $credits = $creditsQuery->get();

            // Obtener configuración del vendedor
            $config = \App\Models\SellerConfig::where('seller_id', $seller->id)->first();
            $renewalQuota = $config ? ($config->notify_renewal_quota ?? 4) : 4;

            // If no credits return empty structure quickly
            if ($credits->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Creditos encontrados',
                    'data' => collect()
                ]);
            }

            // Pre-aggregate payment summary (sum per credit_id and status) in single query
            $creditIds = $credits->pluck('id')->unique()->values()->all();
            $paymentSummaryRows = Payment::whereIn('credit_id', $creditIds)
                ->select('credit_id', 'status', DB::raw('SUM(amount) as total_amount'))
                ->groupBy('credit_id', 'status')
                ->get();

            // Group the summary by credit_id for quick lookup
            $paymentSummary = $paymentSummaryRows->groupBy('credit_id');

            // Precompute "now" in local timezone once
            $nowLocal = Carbon::now($timezone);

            // Transform credits exactly preserving the output shape expected by the front
            $transformedItems = $credits->map(function ($credit) use ($paymentSummary, $nowLocal, $renewalQuota) {
                // Attach aggregated payment totals as properties named by status (same behavior)
                $summary = $paymentSummary->get($credit->id, collect());
                foreach ($summary as $item) {
                    $credit->{$item->status} = $item->total_amount;
                }

                // Move installments to 'installment' key as original code did
                $installments = $credit->installments ?? collect();
                $credit->installment = $installments;
                unset($credit->installments);

                // Determine overdue installments using local timezone
                $overdueInstallments = $installments->filter(function ($installment) use ($nowLocal) {
                    // comparing as dates in local timezone
                    return $installment->due_date && Carbon::parse($installment->due_date, self::TIMEZONE)->lt($nowLocal->startOfDay()) && $installment->status !== 'Pagado';
                });

                $totalInstallments = $installments->count();
                $remainingInstallments = $installments->filter(function ($installment) {
                    return $installment->status !== 'Pagado';
                })->count();

                // Initialize extras
                $credit->overdue_date = null;
                $credit->days_overdue = null;

                if ($overdueInstallments->count() > 0) {
                    $credit->credit_status = 'Overdue';
                    // oldest overdue
                    $firstOverdue = $overdueInstallments->sortBy('due_date')->first();
                    if ($firstOverdue && $firstOverdue->due_date) {
                        $credit->overdue_date = $firstOverdue->due_date;
                        $credit->days_overdue = Carbon::parse($firstOverdue->due_date, self::TIMEZONE)->diffInDays($nowLocal);
                    }
                } elseif ($remainingInstallments <= $renewalQuota && $overdueInstallments->count() === 0) {
                    $credit->credit_status = 'Renewal_pending';
                } elseif ($overdueInstallments->count() <= 2) {
                    $credit->credit_status = 'On_time';
                } else {
                    $credit->credit_status = 'Normal';
                }

                // Calcular el total de créditos activos/vigentes del cliente
                $totalCreditsValue = $credit->client->credits
                    ->whereIn('status', ['Activo', 'Vigente'])
                    ->sum('credit_value');
                $credit->client->total_credits_value = $totalCreditsValue;
                return $credit;
            });

            return response()->json([
                'success' => true,
                'message' => 'Creditos encontrados',
                'data' => $transformedItems,
            ]);
        } catch (\Exception $e) {
            Log::error("Error en getForCollections: {$e->getMessage()} | " . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener créditos'
            ], 500);
        }
    }

    public function toggleStatus($clientId, $status)
    {
        try {
            $client = Client::find($clientId);

            if ($client == null) {
                return $this->errorNotFoundResponse('Cliente no encontrado');
            }

            if (
                $status === 'inactive' &&
                $client->credits()->where('status', 'Vigente')->exists()
            ) {
                return $this->errorResponse(['No se puede desactivar el cliente con créditos vigentes'], 401);
            }
            $client->status = $status;
            $client->save();

            return $this->successResponse([
                'success' => true,
                'message' => "Estado del cliente actualizado con éxito",
                'data' => $client
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al actualizar el estado del cliente', 500);
        }
    }

    public function getDebtorClientsBySeller($sellerId)
    {
        try {


            $clients = Client::with([
                'credits' => function ($query) {
                    $query->with([
                        'installments' => function ($q) {
                            $q->select('*')
                                ->orderBy('due_date', 'asc');
                        }
                    ]);
                },
                'seller' => function ($query) {
                    $query->select('*')
                        ->with(['user' => function ($userQuery) {
                            $userQuery->select('id', 'name');
                        }]);
                },
                'seller.user' => function ($query) {
                    $query->select('id', 'name');
                },
                'guarantors' => function ($query) {
                    $query->select('guarantors.id as id', 'guarantors.name', 'guarantors.dni', 'guarantors.phone');
                }
            ])
                ->where('seller_id', $sellerId)
                ->get();


            if ($clients->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No se encontraron clientes para este vendedor',
                    'data' => []
                ];
            }

            $debtorClients = $clients->map(function ($client) {
                $delinquencyInfo = $this->calculateDelinquencyDetails($client);

                $debtorCredits = $this->getDebtorCredits($client);

                if (empty($debtorCredits)) {
                    return null;
                }

                $clientEntries = [];
                foreach ($debtorCredits as $credit) {
                    $clientEntries[] = [
                        'client_id' => $client->id,
                        'client_name' => $client->name,
                        'client_code' => $client->id,
                        'seller_name' => $client->seller->user->name ?? 'Sin vendedor',
                        'credit_info' => $credit,
                        'delinquency' => [
                            'credit_days_delayed' => max(array_column($credit['installments'], 'days_delayed')),
                            'credit_amount_due' => array_sum(array_column($credit['installments'], 'amount')),
                            'installments_count' => count($credit['installments'])
                        ]
                    ];
                }

                return $clientEntries;
            })
                ->filter()
                ->flatten(1)
                ->filter(function ($clientData) {
                    return $clientData['delinquency']['credit_days_delayed'] > 1;
                })
                ->values();


            $totals = $this->calculateTotals($debtorClients);

            return $this->successResponse([
                'success' => true,
                'message' => 'Créditos morosos encontrados',
                'debug' => [
                    'total_clientes' => $clients->count(),
                    'creditos_con_mora' => $debtorClients->count(),
                    'seller_id' => $sellerId
                ],
                'data' => [
                    'debtor_credits' => $debtorClients,
                    'totals' => $totals,
                    'summary' => [
                        'total_debtor_credits' => $debtorClients->count(),
                        'total_amount_due' => $totals['total_amount_due'],
                        'total_collected' => 0,
                        'report_date' => now()->format('Y-m-d H:i:s')
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error("Error en getDebtorClientsBySeller: " . $e->getMessage());
            /* \Log::error($e->getTraceAsString() */
            return $this->errorResponse('Error al obtener los clientes morosos', 500);
        }
    }

    private function calculateTotals($debtorCredits)
    {
        $totals = [
            'total_debtor_credits' => $debtorCredits->count(),
            'total_amount_due' => 0,
            'total_pending_installments' => 0
        ];

        foreach ($debtorCredits as $credit) {
            $totals['total_amount_due'] += $credit['delinquency']['credit_amount_due'];
            $totals['total_pending_installments'] += $credit['delinquency']['installments_count'];
        }

        return $totals;
    }
    private function calculateDelinquencyDetails($client)
    {
        $totalDaysDelayed = 0;
        $maxDaysDelayed = 0;
        $debtorCredits = 0;
        $totalPendingInstallments = 0;
        $totalAmountDue = 0;
        $delinquencyLevel = 'Al día';

        /*  \Log::info("Calculando morosidad para cliente: {$client->id}");
        \Log::info("  Créditos: " . ($client->credits)); */
        foreach ($client->credits as $credit) {
            $hasDelinquentInstallments = false;
            /* \Log::info("  Analizando crédito: {$credit->id}"); */

            if ($credit->installments) {
                foreach ($credit->installments as $installment) {
                    /* \Log::info("    Analizando cuota: {$installment->number}"); */
                    if ($installment->status !== 'Pagado' && $installment->due_date) {
                        $dueDate = \Carbon\Carbon::parse($installment->due_date);
                        $daysDelayed = now()->diffInDays($dueDate, false) * -1;

                        /* \Log::info("    Cuota {$installment->number}: Estado={$installment->status}, Vence={$installment->due_date}, Días mora={$daysDelayed}"); */

                        if ($daysDelayed > 1) {
                            $hasDelinquentInstallments = true;
                            $totalPendingInstallments++;
                            $totalAmountDue += $installment->amount;
                            $totalDaysDelayed += $daysDelayed;
                            $maxDaysDelayed = max($maxDaysDelayed, $daysDelayed);

                            \Log::info("    ✓ CUOTA MOROSA: {$daysDelayed} días de mora, Monto: {$installment->amount}");
                        }
                    }
                }

                if ($hasDelinquentInstallments) {
                    $debtorCredits++;
                    /* \Log::info("  ✓ CRÉDITO MOROSO: Tiene cuotas con mora"); */
                } else {
                    /*  \Log::info("  ✗ Crédito sin cuotas morosas"); */
                }
            } else {
                /*  \Log::info("  ✗ Crédito sin cuotas"); */
            }
        }

        if ($maxDaysDelayed > 0) {
            if ($maxDaysDelayed <= 15) {
                $delinquencyLevel = 'Morosidad Leve (2-15 días)';
            } elseif ($maxDaysDelayed <= 30) {
                $delinquencyLevel = 'Morosidad Moderada (16-30 días)';
            } elseif ($maxDaysDelayed <= 60) {
                $delinquencyLevel = 'Morosidad Grave (31-60 días)';
            } else {
                $delinquencyLevel = 'Morosidad Crítica (+60 días)';
            }
        }

        /*  \Log::info("Resultado cliente {$client->id}: Max días={$maxDaysDelayed}, Nivel={$delinquencyLevel}, Créditos morosos={$debtorCredits}");
 */
        return [
            'total_days_delayed' => $totalDaysDelayed,
            'max_days_delayed' => $maxDaysDelayed,
            'debtor_credits_count' => $debtorCredits,
            'pending_installments_count' => $totalPendingInstallments,
            'total_amount_due' => $totalAmountDue,
            'delinquency_level' => $delinquencyLevel,
            'has_delinquency' => $maxDaysDelayed > 1
        ];
    }

    private function getDebtorCredits($client)
    {
        $debtorCredits = [];

        foreach ($client->credits as $credit) {
            $creditDelinquency = [
                'credit_id' => $credit->id,
                'credit_code' => $credit->code ?? '#00' . $credit->id,
                'total_amount' => ($credit->credit_value * $credit->total_interest / 100) + $credit->credit_value,
                'balance' => $credit->remaining_amount ?? $credit->balance,
                'number_installments' => $credit->number_installments,
                'installments' => []
            ];

            $hasDelinquentInstallments = false;

            if ($credit->installments) {
                foreach ($credit->installments as $installment) {
                    if ($installment->status !== 'Pagado' && $installment->due_date) {
                        $dueDate = \Carbon\Carbon::parse($installment->due_date);
                        $daysDelayed = now()->diffInDays($dueDate, false) * -1;

                        if ($daysDelayed > 1) {
                            $hasDelinquentInstallments = true;
                            $creditDelinquency['installments'][] = [
                                'installment_number' => $installment->number,
                                'due_date' => $installment->due_date,
                                'amount' => $installment->quota_amount,
                                'days_delayed' => $daysDelayed,
                                'status' => $installment->status,
                                'created_at' => $installment->created_at,
                                'quota_number' => $installment->quota_number
                            ];
                        }
                    }
                }

                if ($hasDelinquentInstallments && !empty($creditDelinquency['installments'])) {
                    $debtorCredits[] = $creditDelinquency;
                }
            }
        }

        return $debtorCredits;
    }

    public function getLiquidationWithAllClients($sellerId, $date, $userId)
    {
        try {
            $user = Auth::user();

            // Verificar permisos del usuario
            if (!in_array($user->role_id, [1, 2, 5])) {
                return response()->json([
                    'error' => 'Unauthorized'
                ], 403);
            }

            // Si es vendedor, verificar que solo acceda a sus datos
            if ($user->role_id == 5 && $user->seller->id != $sellerId) {
                return response()->json([
                    'error' => 'Solo puede acceder a sus propios datos'
                ], 403);
            }

            // 1. Obtener datos de liquidación para la fecha
            $liquidationData = $this->liquidationService->getLiquidationData($sellerId, $date, $userId);

            // 2. Obtener todos los clientes del vendedor con sus créditos filtrados por fecha
            $clientsData = $this->getAllClientsBySellerAndDate($sellerId, $date);
            $allClients = $clientsData['clients'];
            $totalRecaudarHoy = $clientsData['total_recaudar_hoy'];

            // 3. Combinar los resultados
            $totalClients = count($allClients);
            return [
                'liquidation' => $liquidationData,
                'clients' => $allClients,
                'summary' => array_merge(
                    $liquidationData['summary'] ?? [],
                    [
                        'total_clients' => $totalClients,
                        'total_recaudar_hoy' => $totalRecaudarHoy
                    ]
                )
            ];
        } catch (\Exception $e) {
            \Log::error("Error en getLiquidationWithAllClients: " . $e->getMessage());
            /*    \Log::error($e->getTraceAsString()); */
            return $this->errorResponse('Error al obtener los datos de liquidación y clientes', 500);
        }
    }

    private function getAllClientsBySellerAndDate($sellerId, $date)
    {
        $referenceDate = Carbon::createFromFormat('Y-m-d', $date, self::TIMEZONE);
        $formattedDate = $referenceDate->format('Y-m-d');

        $excludeStatuses = ['Liquidado', 'Renovado', 'Unificado', 'Cartera Irrecuperable'];

        $clients = Client::with([
            'credits' => function ($q) use ($excludeStatuses, $formattedDate) {

                $q->where(function ($query) use ($excludeStatuses, $formattedDate) {
                    // Opción 1: Créditos que aún están vigentes (no en estado final)
                    $query->whereNotIn('status', $excludeStatuses)

                        ->orWhere(function ($q2) use ($formattedDate) {
                            $q2->whereIn('status', ['Liquidado', 'Renovado', 'Unificado'])
                                ->whereDate('updated_at', $formattedDate);
                        });
                });
            },

            'credits.installments' => function ($query) use ($formattedDate) {
                $query->where('due_date', $formattedDate);
                $query->orderBy('due_date', 'asc');
            },

            'seller.user:id,name',
            'guarantors:id,name'
        ])
            ->where('seller_id', $sellerId)
            ->get(['id', 'name', 'seller_id']);

        $result = [];
        $totalRecaudarHoy = 0;

        foreach ($clients as $client) {
            foreach ($client->credits as $credit) {

                $cuotasHoyCollection = $credit->installments->filter(function ($installment) use ($formattedDate) {
                    return $installment->due_date == $formattedDate;
                });

                $totalRecaudarHoy += $cuotasHoyCollection->sum('quota_amount');

                $estadoEspecial = $credit->status;
                if (in_array($credit->status, ['Liquidado', 'Renovado', 'Unificado'])) {
                    $liquidationDate = \Carbon\Carbon::parse($credit->updated_at, self::TIMEZONE)->format('Y-m-d');
                    if ($liquidationDate == $formattedDate) {
                        $estadoEspecial = $credit->status . ' hoy';
                    }
                }

                $delinquencyInfo = $this->calculateDelinquencyDetailsForDate($client, $referenceDate);
                $creditInfo = $this->getCreditInfoForDate($credit, $referenceDate);

                // 4. Agregar el resultado
                $result[] = [
                    'client' => $client,
                    'client_id' => $client->id,
                    'credit_id' => $credit->id,
                    'client_name' => $client->name,
                    'client_code' => $client->id,
                    'credit_info' => $credit,

                    'installment' => $cuotasHoyCollection,
                    'client_code' => $client->id,
                    'credit_info' => $credit,

                    'installment' => $cuotasHoyCollection,

                    'seller_name' => $client->seller->user->name ?? 'Sin vendedor',
                    'credit' => $creditInfo,
                    'delinquency_summary' => $delinquencyInfo,
                    'credit_status' => $estadoEspecial,

                    // Usamos cuotas_hoy para mantener la compatibilidad con el código anterior
                    'cuotas_hoy' => $cuotasHoyCollection,
                ];
            }
        }

        // 5. Retornar el resultado final
        return [
            'clients' => $result,
            'total_recaudar_hoy' => $totalRecaudarHoy
        ];
    }
    private function isCreditActiveOnDate($credit, $referenceDate)
    {
        $creditDate = \Carbon\Carbon::parse($credit->created_at);
        $creditEndDate = $creditDate->copy()->addMonths($credit->number_installments);

        return $referenceDate->between($creditDate, $creditEndDate);
    }

    private function getCreditInfoForDate($credit, $referenceDate)
    {

        $startUTC = $referenceDate->copy()->startOfDay()->timezone('UTC');
        $endUTC = $referenceDate->copy()->endOfDay()->timezone('UTC');
        // PAGOS DEL DÍA
        $todayPayments = $credit->payments->filter(function ($payment) use ($startUTC, $endUTC) {
            return $payment->created_at >= $startUTC && $payment->created_at <= $endUTC;
        });
        $paidToday = $todayPayments->sum('amount');

        // PAGOS DEL DÍA POR MÉTODO
        $paidTodayEfectivo = $todayPayments->where('payment_method', 'Efectivo')->sum('amount');
        $paidTodayTransferencia = $todayPayments->where('payment_method', 'Transferencia')->sum('amount');
        $imagenTransferencia = null;
        $lastTransferenciaPago = $todayPayments->where('payment_method', 'Transferencia')->sortByDesc('created_at')->first();
        $imagenTransferencia = null;
        if ($lastTransferenciaPago) {
            $paymentImage = $lastTransferenciaPago->image;
            if ($paymentImage) {
                $imagenTransferencia = $paymentImage->path; // Cambia a tu campo real
            }
        }

        // CUOTAS PAGADAS HOY
        $installmentsPaidToday = [];
        foreach ($todayPayments as $payment) {
            // Cada pago puede tener varias cuotas (PaymentInstallment)
            foreach ($payment->installments as $paymentInstallment) {
                // Accede a la cuota real
                $installment = $paymentInstallment->installment;
                if ($installment) {
                    $installmentsPaidToday[] = [
                        'quota_number' => $installment->quota_number,
                        'installment_id' => $installment->id,
                        'applied_amount' => $paymentInstallment->applied_amount,
                    ];
                }
            }
        }


        // ÚLTIMO PAGO DEL DÍA
        $lastPaymentToday = $todayPayments->sortByDesc('created_at')->first();
        $ultimoPagoMetodo = $lastPaymentToday ? $lastPaymentToday->payment_method : null;
        $ultimoPagoMonto = $lastPaymentToday ? $lastPaymentToday->amount : null;

        // METAS DEL DÍA: sumar cuotas con due_date = referenceDate
        $metaRecaudarHoy = $credit->installments->where('due_date', $referenceDate->format('Y-m-d'))->sum('quota_amount');
        $faltantePorRecaudarHoy = $metaRecaudarHoy - ($paidTodayEfectivo + $paidTodayTransferencia);

        $paidAmount = $credit->payments
            ->filter(function ($payment) use ($referenceDate) {
                return $payment->created_at <= $referenceDate->copy()->endOfDay()->timezone('UTC');
            })
            ->sum('amount');

        $totalAmount = ($credit->credit_value * $credit->total_interest / 100) + $credit->credit_value;
        $balance = $totalAmount - $paidAmount;

        $frequency = $credit->payment_frequency; // diaria, semanal, quincenal, mensual
        $pagoFrecuencia = "";

        if ($frequency === 'diaria') {
            $pagoFrecuencia = "Pago diario";
        } else if ($frequency === 'semanal') {
            // Buscar el día de la semana de la próxima cuota
            $nextInstallment = $credit->installments->where('due_date', '>=', $referenceDate->format('Y-m-d'))->sortBy('due_date')->first();
            if ($nextInstallment) {
                $dueDate = \Carbon\Carbon::parse($nextInstallment->due_date);
                $dayName = $dueDate->locale('es')->dayName; // Ejemplo: miércoles
                $pagoFrecuencia = "Pago semanal - los " . $dayName;
            } else {
                $pagoFrecuencia = "Pago semanal";
            }
        } else if ($frequency === 'quincenal' || $frequency === 'mensual') {
            // Buscar el día del mes de la próxima cuota
            $nextInstallment = $credit->installments->where('due_date', '>=', $referenceDate->format('Y-m-d'))->sortBy('due_date')->first();
            if ($nextInstallment) {
                $dueDate = \Carbon\Carbon::parse($nextInstallment->due_date);
                $dayOfMonth = $dueDate->day;
                $pagoFrecuencia = "Pago " . $frequency . " - el día " . $dayOfMonth;
            } else {
                $pagoFrecuencia = "Pago " . $frequency;
            }
        }

        $creditInfo = [
            'credit_id' => $credit->id,
            'credit_code' => $credit->code ?? '#00' . $credit->id,
            'total_amount' => $totalAmount,
            'paid_amount' => $credit->status === 'Renovado' ? $totalAmount : $paidAmount,
            'payment_frequency' => $credit->payment_frequency,
            'payment_day_info' => $pagoFrecuencia,
            'balance' => $credit->status === 'Renovado' ? 0 : $balance,
            'paid_today' => $paidToday,
            'created_at' => $credit->created_at,
            'payment_method_today' => $ultimoPagoMetodo,
            'number_installments' => $credit->number_installments,
            'status' => $credit->status,
            'installments' => [],
            'payments' => [],
            'cuotas_pagadas_hoy' => $installmentsPaidToday,
            'meta_a_recaudar_hoy' => $metaRecaudarHoy,
            'recaudado_efectivo_hoy' => $paidTodayEfectivo,
            'recaudado_transferencia_hoy' => $paidTodayTransferencia,
            'imagen_transferencia_hoy' => $imagenTransferencia,
            'faltante_por_recaudar_hoy' => $faltantePorRecaudarHoy,
            'ultimo_pago_metodo_hoy' => $ultimoPagoMetodo,
            'ultimo_pago_monto_hoy' => $ultimoPagoMonto,
        ];

        foreach ($credit->payments as $payment) {
            $paymentDate = \Carbon\Carbon::parse($payment->payment_date);
            $daysDelayed = max(0, $referenceDate->diffInDays($paymentDate, false) * -1);

            $creditInfo['payments'][] = [
                'payment_id' => $payment->id,
                'payment_date' => $payment->payment_date,
                'amount' => $payment->amount,
                'days_delayed' => $daysDelayed,
                'created_at' => $payment->created_at
            ];
        }

        foreach ($credit->installments as $installment) {
            $dueDate = \Carbon\Carbon::parse($installment->due_date);
            $daysDelayed = max(0, $referenceDate->diffInDays($dueDate, false) * -1);

            // Determinar el estado de la cuota basado en pagos hasta la fecha de referencia
            $installmentStatus = $this->getInstallmentStatusOnDate($installment, $referenceDate);
            $paidAmountInstallment = $this->getInstallmentPaidAmount($installment, $referenceDate);

            $creditInfo['installments'][] = [
                'installment_number' => $installment->number,
                'due_date' => $installment->due_date,
                'amount' => $installment->quota_amount,
                'days_delayed' => $daysDelayed,
                'status' => $installmentStatus,
                'paid_amount' => $paidAmountInstallment,
                'created_at' => $installment->created_at,
                'quota_number' => $installment->quota_number
            ];
        }

        return $creditInfo;
    }

    private function getCreditStatusOnDate($credit, $referenceDate, $balance)
    {
        if ($balance <= 0) {
            return 'Pagado';
        }

        // Obtener la última cuota vencida
        $lastInstallment = $credit->installments
            ->where('due_date', '<=', $referenceDate->format('Y-m-d'))
            ->sortByDesc('due_date')
            ->first();

        if (!$lastInstallment) {
            return 'En curso';
        }

        $lastDueDate = \Carbon\Carbon::parse($lastInstallment->due_date);

        if ($referenceDate->gt($lastDueDate)) {
            return 'Vencido';
        }

        return 'En curso';
    }

    private function getInstallmentStatusOnDate($installment, $referenceDate)
    {
        $dueDate = \Carbon\Carbon::parse($installment->due_date);
        $paidAmount = $this->getInstallmentPaidAmount($installment, $referenceDate);

        // Si se pagó completo hasta la fecha de referencia
        if ($paidAmount >= $installment->quota_amount) {
            return 'Pagado';
        }

        // Si la fecha de referencia es posterior a la fecha de vencimiento y no está pagado
        if ($referenceDate->gt($dueDate)) {
            return 'Vencido';
        }

        return 'Pendiente';
    }

    private function getInstallmentPaidAmount($installment, $referenceDate)
    {
        if (!isset($installment->payments)) {
            return 0;
        }

        return $installment->payments
            ->filter(function ($paymentInstallment) use ($referenceDate) {
                return $paymentInstallment->payment &&
                    $paymentInstallment->payment->created_at <= $referenceDate->format('Y-m-d');
            })
            ->sum('applied_amount');
    }



    private function getCreditsInfoForDate($client, $referenceDate)
    {
        $creditsInfo = [];

        foreach ($client->credits as $credit) {
            $creditInfo = [
                'credit_id' => $credit->id,
                'credit_code' => $credit->code ?? '#00' . $credit->id,
                'total_amount' => ($credit->credit_value * $credit->total_interest / 100) + $credit->credit_value,
                'balance' => $credit->remaining_amount ?? $credit->balance,
                'number_installments' => $credit->number_installments,
                'installments' => []
            ];

            if ($credit->installments) {
                foreach ($credit->installments as $installment) {
                    $dueDate = \Carbon\Carbon::parse($installment->due_date);
                    $daysDelayed = $referenceDate->diffInDays($dueDate, false) * -1;

                    $creditInfo['installments'][] = [
                        'installment_number' => $installment->number,
                        'due_date' => $installment->due_date,
                        'amount' => $installment->quota_amount,
                        'days_delayed' => $daysDelayed > 0 ? $daysDelayed : 0,
                        'status' => $installment->status,
                        'created_at' => $installment->created_at,
                        'quota_number' => $installment->quota_number
                    ];
                }
            }

            $creditsInfo[] = $creditInfo;
        }

        return $creditsInfo;
    }

    private function calculateDelinquencyDetailsForDate($client, $referenceDate)
    {
        $totalDaysDelayed = 0;
        $maxDaysDelayed = 0;
        $debtorCredits = 0;
        $totalPendingInstallments = 0;
        $totalAmountDue = 0;
        $delinquencyLevel = 'Al día';

        foreach ($client->credits as $credit) {
            $hasDelinquentInstallments = false;

            if ($credit->installments) {
                foreach ($credit->installments as $installment) {
                    $dueDate = \Carbon\Carbon::parse($installment->due_date);
                    $daysDelayed = $referenceDate->diffInDays($dueDate, false) * -1;

                    // Solo considerar cuotas vencidas y no pagadas completamente hasta la fecha de referencia
                    $installmentStatus = $this->getInstallmentStatusOnDate($installment, $referenceDate);
                    $paidAmount = $this->getInstallmentPaidAmount($installment, $referenceDate);

                    if ($installmentStatus === 'Vencido') {
                        $hasDelinquentInstallments = true;
                        $totalPendingInstallments++;
                        $totalAmountDue += ($installment->quota_amount - $paidAmount);
                        $totalDaysDelayed += $daysDelayed;
                        $maxDaysDelayed = max($maxDaysDelayed, $daysDelayed);
                    }
                }

                if ($hasDelinquentInstallments) {
                    $debtorCredits++;
                }
            }
        }

        if ($maxDaysDelayed > 0) {
            if ($maxDaysDelayed <= 15) {
                $delinquencyLevel = 'Morosidad Leve (2-15 días)';
            } elseif ($maxDaysDelayed <= 30) {
                $delinquencyLevel = 'Morosidad Moderada (16-30 días)';
            } elseif ($maxDaysDelayed <= 60) {
                $delinquencyLevel = 'Morosidad Grave (31-60 días)';
            } else {
                $delinquencyLevel = 'Morosidad Crítica (+60 días)';
            }
        }

        return [
            'total_days_delayed' => $totalDaysDelayed,
            'max_days_delayed' => $maxDaysDelayed,
            'debtor_credits_count' => $debtorCredits,
            'pending_installments_count' => $totalPendingInstallments,
            'total_amount_due' => $totalAmountDue,
            'delinquency_level' => $delinquencyLevel,
            'has_delinquency' => $maxDaysDelayed > 1
        ];
    }



    public function getCollectionSummary(string $date = null)
    {

        $user = Auth::user();
        $seller = $user->seller;
        $sellerId = $seller ? $seller->id : null;
        $userId = $user->id;

        $timezone = self::TIMEZONE;
        $startUTC = $date
            ? Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay()->timezone('UTC')
            : Carbon::now($timezone)->startOfDay()->timezone('UTC');
        $endUTC = $date
            ? Carbon::createFromFormat('Y-m-d', $date, $timezone)->endOfDay()->timezone('UTC')
            : Carbon::now($timezone)->endOfDay()->timezone('UTC');

        // 1. TOTAL ESPERADO (considerando soft deletes)
        $expectedQuery = DB::table('installments')
            ->selectRaw('COALESCE(SUM(quota_amount), 0) as total_expected')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->whereNull('installments.deleted_at')
            ->whereNull('credits.deleted_at')
            ->whereNull('clients.deleted_at')
            ->where('credits.status', '!=', ['Unificado', 'Renovado', 'Cartera Irrecuperable'])
            ->where(function ($query) use ($date) {
                $query->whereDate('due_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->where('installments.status', 'Pendiente')
                            ->whereDate('due_date', '=', $date);
                    });
            });

        if ($sellerId) {
            $expectedQuery->where('credits.seller_id', $sellerId);
        }
        $expected = $expectedQuery->first();

        // 2. PAGOS DEL DÍA (considerando soft deletes)
        $todayPaymentsQuery = DB::table('payments')
            ->selectRaw('
                COALESCE(SUM(payments.amount), 0) as total_collected_today,
                COALESCE(SUM(CASE WHEN payments.status = "Pagado" THEN payments.amount ELSE 0 END), 0) as total_paid_today,
                COALESCE(SUM(CASE WHEN payments.status = "Abonado" THEN payments.amount ELSE 0 END), 0) as total_deposits_today,
                COUNT(DISTINCT credits.client_id) as clients_paid_today
            ')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->whereNull('payments.deleted_at')
            ->whereNull('credits.deleted_at')
            ->whereNull('clients.deleted_at')
            ->whereBetween('payments.created_at', [$startUTC, $endUTC])
            ->whereIn('payments.status', ['Pagado', 'Abonado']);

        if ($sellerId) {
            $todayPaymentsQuery->where('credits.seller_id', $sellerId);
        }
        $todayPayments = $todayPaymentsQuery->first();

        // 3. PAGOS NO PAGADOS HOY (considerando soft deletes)
        $todayUnpaidPaymentsQuery = DB::table('payments')
            ->selectRaw('COALESCE(SUM(installments.quota_amount), 0) as total_unpaid_today')
            ->join('payment_installments', 'payments.id', '=', 'payment_installments.payment_id')
            ->join('installments', 'payment_installments.installment_id', '=', 'installments.id')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->whereNull('payments.deleted_at')
            ->whereNull('payment_installments.deleted_at')
            ->whereNull('installments.deleted_at')
            ->whereNull('credits.deleted_at')
            ->whereNull('clients.deleted_at')
            ->whereBetween('payments.created_at', [$startUTC, $endUTC])
            ->where('payments.status', 'No Pagado');

        if ($sellerId) {
            $todayUnpaidPaymentsQuery->where('credits.seller_id', $sellerId);
        }
        $todayUnpaidPayments = $todayUnpaidPaymentsQuery->first();

        // 4. ABONOS HOY (considerando soft deletes)
        $todayAbonosQuery = DB::table('payments')
            ->selectRaw('COALESCE(SUM(payments.amount), 0) as total_deposits_today')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->whereNull('payments.deleted_at')
            ->whereNull('credits.deleted_at')
            ->whereNull('clients.deleted_at')
            ->whereBetween('payments.created_at', [$startUTC, $endUTC])
            ->where('payments.status', 'Abonado');

        if ($sellerId) {
            $todayAbonosQuery->where('credits.seller_id', $sellerId);
        }
        $todayAbonos = $todayAbonosQuery->first();

        // 5. RECAUDACIÓN DE CUOTAS VENCIDAS HOY (considerando soft deletes)
        $todayDueCollectedQuery = DB::table('payment_installments')
            ->selectRaw('
                COALESCE(SUM(payment_installments.applied_amount), 0) as total_collected,
                COALESCE(SUM(CASE WHEN payments.status = "Pagado" THEN payment_installments.applied_amount ELSE 0 END), 0) as total_paid,
                COALESCE(SUM(CASE WHEN payments.status = "Abonado" THEN payments.amount ELSE 0 END), 0) as total_deposits
            ')
            ->join('installments', 'payment_installments.installment_id', '=', 'installments.id')
            ->join('payments', 'payment_installments.payment_id', '=', 'payments.id')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->whereNull('payment_installments.deleted_at')
            ->whereNull('installments.deleted_at')
            ->whereNull('payments.deleted_at')
            ->whereNull('credits.deleted_at')
            ->whereNull('clients.deleted_at')
            ->where(function ($query) use ($date) {
                $query->whereDate('installments.due_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->where('installments.status', 'Pendiente')
                            ->whereDate('due_date', '<', $date);
                    });
            })
            ->whereIn('payments.status', ['Pagado', 'Abonado']);

        if ($sellerId) {
            $todayDueCollectedQuery->where('credits.seller_id', $sellerId);
        }
        $todayDueCollected = $todayDueCollectedQuery->first();

        // 6. ESTADÍSTICAS DE CLIENTES (considerando soft deletes)
        $clientsQuery = DB::table('clients')
            ->selectRaw('
                COUNT(DISTINCT clients.id) as total_clients,
                COUNT(DISTINCT CASE WHEN payments.id IS NOT NULL THEN clients.id END) as clients_served,
                COUNT(DISTINCT CASE WHEN payments.status = "Abonado" THEN clients.id END) as pending_clients,
                COUNT(DISTINCT CASE WHEN payments.id IS NOT NULL AND payments.status != "Pagado" 
                                    THEN clients.id END) as defaulted_clients
            ')
            ->join('credits', 'clients.id', '=', 'credits.client_id')
            ->join('installments', 'credits.id', '=', 'installments.credit_id')
            ->leftJoin('payment_installments', function ($join) {
                $join->on('installments.id', '=', 'payment_installments.installment_id')
                    ->whereNull('payment_installments.deleted_at');
            })
            ->leftJoin('payments', function ($join) {
                $join->on('payment_installments.payment_id', '=', 'payments.id')
                    ->whereNull('payments.deleted_at')
                    ->whereIn('payments.status', ['Pagado', 'Abonado']);
            })
            ->whereNull('clients.deleted_at')
            ->whereNull('credits.deleted_at')
            ->whereNull('installments.deleted_at')
            ->where(function ($query) use ($date) {
                $query->whereDate('installments.due_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->where('installments.status', 'Pendiente')
                            ->whereDate('installments.due_date', '<', $date);
                    });
            });

        if ($sellerId) {
            $clientsQuery->where('credits.seller_id', $sellerId);
        }
        $clientCounts = $clientsQuery->first();

        // 7. CLIENTES ATENDIDOS HOY (considerando soft deletes)
        $attendedTodayQuery = DB::table('payments')
            ->selectRaw('COUNT(DISTINCT credits.client_id) as attended_today')
            ->leftJoin('payment_installments', 'payments.id', '=', 'payment_installments.payment_id')
            ->leftJoin('installments', 'payment_installments.installment_id', '=', 'installments.id')
            ->leftJoin('credits', function ($join) {
                $join->on('installments.credit_id', '=', 'credits.id')
                    ->orOn('payments.credit_id', '=', 'credits.id');
            })
            ->whereNull('payments.deleted_at')
            ->whereNull('payment_installments.deleted_at')
            ->whereNull('installments.deleted_at')
            ->whereNull('credits.deleted_at')
            ->whereBetween('payments.created_at', [$startUTC, $endUTC]);


        if ($sellerId) {
            $attendedTodayQuery->where('credits.seller_id', $sellerId);
        }
        $attendedToday = $attendedTodayQuery->value('attended_today') ?? 0;

        // 8. CLIENTES PENDIENTES HOY (considerando soft deletes)
        $pendingTodayQuery = DB::table('clients')
            ->selectRaw('COUNT(DISTINCT clients.id) as pending_today')
            ->join('credits', 'clients.id', '=', 'credits.client_id')
            ->join('installments', 'credits.id', '=', 'installments.credit_id')
            ->whereNull('clients.deleted_at')
            ->whereNull('credits.deleted_at')
            ->whereNull('installments.deleted_at')
            ->where('credits.status', '!=', 'Unificado')
            ->whereDate('installments.due_date', $date)
            ->whereNotExists(function ($query) use ($startUTC, $endUTC) {
                $query->select(DB::raw(1))
                    ->from('payments')
                    ->whereNull('payments.deleted_at')
                    ->where(function ($q) {
                        $q->whereColumn('payments.credit_id', 'credits.id')
                            ->orWhereExists(function ($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('payment_installments')
                                    ->join('installments as i', 'payment_installments.installment_id', '=', 'i.id')
                                    ->whereNull('payment_installments.deleted_at')
                                    ->whereNull('i.deleted_at')
                                    ->whereColumn('i.credit_id', 'credits.id')
                                    ->whereColumn('payment_installments.payment_id', 'payments.id');
                            });
                    })
                    ->whereBetween('payments.created_at', [$startUTC, $endUTC]);
            });

        if ($sellerId) {
            $pendingTodayQuery->where('credits.seller_id', $sellerId);
        }
        $pendingToday = $pendingTodayQuery->value('pending_today') ?? 0;

        // 9. CRÉDITOS ACTIVOS CREADOS HOY (considerando soft deletes)
        $activeCreditsTodayQuery = DB::table('credits')
            ->selectRaw('COUNT(credits.id) as total_active_credits_today')
            ->whereNull('credits.deleted_at')
            ->whereBetween('credits.created_at', [$startUTC, $endUTC])
            ->where('credits.status', 'Vigente');

        if ($sellerId) {
            $activeCreditsTodayQuery->where('credits.seller_id', $sellerId);
        }
        $activeCreditsToday = $activeCreditsTodayQuery->first();

        // 10. GASTOS DEL DÍA (considerando soft deletes)
        $dailyExpensesQuery = DB::table('expenses')
            ->selectRaw('COALESCE(SUM(expenses.value), 0) as total_expenses_today')
            ->whereNull('expenses.deleted_at')
            ->whereBetween('expenses.created_at', [$startUTC, $endUTC]);

        if ($sellerId) {
            $dailyExpensesQuery->where('expenses.user_id', $userId);
        }
        $dailyExpenses = $dailyExpensesQuery->first();

        // 11. CLIENTES EN MORA HOY (considerando soft deletes)
        $defaultedClientsCountQuery = DB::table('clients')
            ->join('credits', 'clients.id', '=', 'credits.client_id')
            ->join('installments', 'credits.id', '=', 'installments.credit_id')
            ->join('payment_installments', 'installments.id', '=', 'payment_installments.installment_id')
            ->join('payments', 'payment_installments.payment_id', '=', 'payments.id')
            ->whereNull('clients.deleted_at')
            ->whereNull('credits.deleted_at')
            ->whereNull('installments.deleted_at')
            ->whereNull('payment_installments.deleted_at')
            ->whereNull('payments.deleted_at')
            ->whereBetween('payments.created_at', [$startUTC, $endUTC])
            ->where(function ($query) {
                $query->where('payments.status', '!=', 'Pagado')
                    ->where('payments.status', '!=', 'Abonado');
            })
            ->select('clients.id', 'clients.name', 'payments.status');

        $result = $defaultedClientsCountQuery->get();

        /* Log::info('Clientes contados como defaulted:', ['clientes' => $result]); */

        if ($sellerId) {
            $defaultedClientsCountQuery->where('credits.seller_id', $sellerId);
        }
        $defaultedClientsCount = $defaultedClientsCountQuery->distinct()->count('clients.id');

        // RESULTADO FINAL
        $summary = [
            'totalExpected' => $expected->total_expected ?? 0,
            'totalCollectedForDue' => $todayDueCollected->total_collected ?? 0,
            'totalPaidForDue' => $todayDueCollected->total_paid ?? 0,
            'totalDepositsForDue' => $todayDueCollected->total_deposits ?? 0,
            'totalUnpaid' => ($expected->total_expected ?? 0) - ($todayDueCollected->total_collected ?? 0),
            'defaultedClientsCount' => $defaultedClientsCount ?? 0,
            'totalCollectedToday' => $todayPayments->total_collected_today ?? 0,
            'totalPaidToday' => $todayPayments->total_paid_today ?? 0,
            'totalDepositsToday' => $todayAbonos->total_deposits_today ?? 0,
            'clientsPaidToday' => $todayPayments->clients_paid_today ?? 0,
            'totalUnpaidPaymentAmountToday' => $todayUnpaidPayments->total_unpaid_today ?? 0,
            'clientsServed' => $attendedToday ?? 0,
            'pendingClients' => $clientCounts->pending_clients ?? 0,
            'defaultedClients' => $clientCounts->defaulted_clients ?? 0,
            'totalClients' => $pendingToday ?? 0,
            'totalPaid' => $todayPayments->total_paid_today ?? 0,
            'totalDeposits' => $todayPayments->total_deposits_today ?? 0,
            'totalUnpaidClients' => $clientCounts->defaulted_clients ?? 0,
            'totalActiveCreditsToday' => $activeCreditsToday->total_active_credits_today ?? 0,
            'totalExpensesToday' => $dailyExpenses->total_expenses_today ?? 0,
        ];

        return response()->json([
            'success' => true,
            'message' => 'Resumen de cobranza diaria',
            'data' => $summary
        ]);
    }

    public function getClientPortfolioBySeller($sellerId, $startDate, $endDate)
    {
        $timezone = self::TIMEZONE;
        $startUTC = Carbon::createFromFormat('Y-m-d', $startDate, $timezone)->startOfDay()->timezone('UTC');
        $endUTC = Carbon::createFromFormat('Y-m-d', $endDate, $timezone)->endOfDay()->timezone('UTC');

        return DB::table('credits')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->leftJoin('payments', function ($join) use ($startUTC, $endUTC) {
                $join->on('credits.id', '=', 'payments.credit_id')
                    ->whereBetween('payments.created_at', [$startUTC, $endUTC]);
            })
            ->select(
                'credits.id as loan_id',
                'clients.name as client_name',
                'credits.credit_value as capital',
                DB::raw('COALESCE(SUM(payments.amount), 0) as paid_value'),
                DB::raw('COALESCE(credits.remaining_amount, 0) as credit_balance'),
                DB::raw('COALESCE(credits.total_amount - COALESCE(SUM(payments.amount), 0), 0) as portfolio_balance')
            )
            ->where('credits.seller_id', $sellerId)
            ->whereNull('credits.deleted_at')
            ->whereNull('clients.deleted_at')
            ->groupBy('credits.id', 'clients.name', 'credits.credit_value', 'credits.remaining_amount', 'credits.total_amount')
            ->get();
    }

    public function getTotalCollectedBySeller($sellerId, $startDate, $endDate)
    {
        $timezone = self::TIMEZONE;
        $startUTC = Carbon::createFromFormat('Y-m-d', $startDate, $timezone)->startOfDay()->timezone('UTC');
        $endUTC = Carbon::createFromFormat('Y-m-d', $endDate, $timezone)->endOfDay()->timezone('UTC');

        return DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->whereBetween('payments.created_at', [$startUTC, $endUTC])
            ->whereNull('payments.deleted_at')
            ->sum('payments.amount');
    }

    public function getInactiveClientsWithoutCredits()
    {
        $clients = Client::where('status', 'inactive')
            ->whereDoesntHave('credits', function ($q) {
                $q->where('status', 'Vigente');
            })
            ->get();

        return $this->successResponse([
            'success' => true,
            'message' => 'Clientes inactivos sin créditos vigentes encontrados',
            'data' => $clients
        ]);
    }
    public function reactivateClientsByIds(array $clientIds)
    {
        try {
            /* \Log::info('IDs recibidos para reactivación:', $clientIds); */

            // Incluye clientes eliminados (soft-deleted) en la consulta
            $clients = Client::withTrashed()->whereIn('id', $clientIds)->get();
            /* \Log::info('Clientes encontrados:', $clients->toArray()); */

            if ($clients->isEmpty()) {
                throw new \Exception('No se encontraron clientes con los IDs proporcionados.');
            }

            foreach ($clients as $client) {
                // Restaura el cliente si está eliminado
                if ($client->trashed()) {
                    $client->restore();
                    /* \Log::info("Cliente restaurado: {$client->id}"); */
                }

                // Actualiza el estado del cliente a "active"
                $client->update(['status' => 'active']);
                /*   \Log::info("Cliente reactivado: {$client->id}, Estado: {$client->status}"); */
            }

            return $clients;
        } catch (\Exception $e) {
            \Log::error("Error reactivando clientes: " . $e->getMessage());
            throw new \Exception('Error al reactivar clientes');
        }
    }

    public function getInactiveClientsWithoutCreditsWithFilters(
        $search = '',
        $orderBy = 'created_at',
        $orderDirection = 'desc',
        $countryId = null,
        $cityId = null,
        $sellerId = null
    ) {
        try {
            $search = (string) $search;

            $user = Auth::user();
            $seller = $user->seller;

            // Consulta principal para clientes inactivos sin créditos vigentes
            $clientsQuery = Client::query()
                ->where('status', 'inactive')
                ->whereDoesntHave('credits', function ($q) {
                    $q->where('status', 'Vigente');
                })
                ->with([
                    'seller',
                    'seller.city',
                    'seller.city.country',
                ])
                ->select('clients.*');

            // Filtro por búsqueda
            if (!empty(trim($search))) {
                $clientsQuery->where(function ($query) use ($search) {
                    $query->where('clients.name', 'like', "%{$search}%")
                        ->orWhere('clients.dni', 'like', "%{$search}%")
                        ->orWhere('clients.email', 'like', "%{$search}%");
                });
            }

            // Filtro por país
            if ($countryId) {
                $clientsQuery->whereHas('seller.city.country', function ($q) use ($countryId) {
                    $q->where('id', $countryId);
                });
            }

            // Filtro por ciudad
            if ($cityId) {
                $clientsQuery->whereHas('seller.city', function ($q) use ($cityId) {
                    $q->where('id', $cityId);
                });
            }

            // Filtro por vendedor
            if ($sellerId) {
                $clientsQuery->where('clients.seller_id', $sellerId);
            } elseif ($user->role_id == 5 && $seller) {
                $clientsQuery->where('clients.seller_id', $seller->id);
            }

            // Ordenación
            $validOrderDirections = ['asc', 'desc'];
            $orderDirection = in_array(strtolower($orderDirection), $validOrderDirections)
                ? $orderDirection
                : 'desc';

            $clientsQuery->orderBy($orderBy, $orderDirection);

            // Obtener resultados
            $clients = $clientsQuery->get();

            return $this->successResponse([
                'success' => true,
                'message' => 'Clientes inactivos sin créditos vigentes encontrados',
                'data' => $clients
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los clientes inactivos sin créditos', 500);
        }
    }

    public function getDeletedClientsWithFilters(
        $search = '',
        $orderBy = 'deleted_at',
        $orderDirection = 'desc',
        $countryId = null,
        $cityId = null,
        $sellerId = null
    ) {
        try {
            $search = (string) $search;

            $user = Auth::user();
            $seller = $user->seller;

            // Consulta principal para clientes eliminados
            $clientsQuery = Client::onlyTrashed() // Solo clientes eliminados (soft-deleted)
                ->with([
                    'seller',
                    'seller.city',
                    'seller.city.country',
                ])
                ->select('clients.*');

            // Filtro por búsqueda
            if (!empty(trim($search))) {
                $clientsQuery->where(function ($query) use ($search) {
                    $query->where('clients.name', 'like', "%{$search}%")
                        ->orWhere('clients.dni', 'like', "%{$search}%")
                        ->orWhere('clients.email', 'like', "%{$search}%");
                });
            }

            // Filtro por país
            if ($countryId) {
                $clientsQuery->whereHas('seller.city.country', function ($q) use ($countryId) {
                    $q->where('id', $countryId);
                });
            }

            // Filtro por ciudad
            if ($cityId) {
                $clientsQuery->whereHas('seller.city', function ($q) use ($cityId) {
                    $q->where('id', $cityId);
                });
            }

            // Filtro por vendedor
            if ($sellerId) {
                $clientsQuery->where('clients.seller_id', $sellerId);
            } elseif ($user->role_id == 5 && $seller) {
                $clientsQuery->where('clients.seller_id', $seller->id);
            }

            // Ordenación
            $validOrderDirections = ['asc', 'desc'];
            $orderDirection = in_array(strtolower($orderDirection), $validOrderDirections)
                ? $orderDirection
                : 'desc';

            $clientsQuery->orderBy($orderBy, $orderDirection);

            // Obtener resultados
            $clients = $clientsQuery->get();

            return $this->successResponse([
                'success' => true,
                'message' => 'Clientes eliminados encontrados',
                'data' => $clients
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los clientes eliminados', 500);
        }
    }

    public function updateCapacity($clientId, $capacity)
    {
        $client = Client::findOrFail($clientId);
        $client->capacity = $capacity;
        $client->save();
        return $client;
    }
}
