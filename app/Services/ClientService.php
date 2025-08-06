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
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClientService
{
    use ApiResponse;

    public function create(ClientRequest $request)
    {
        try {
            $params = $request->validated();
            \Log::info('Datos recibidos:', $params);

            if ($request->has('images')) {
                $validationResponse = $this->validateImages($request);
                if ($validationResponse !== true) {
                    return $validationResponse;
                }
            }

            $guarantorId = null;

            if (!empty($params['guarantor_name'])) {
                $guarantorData = [
                    'name' => $params['guarantor_name'],
                    'dni' => $params['guarantor_dni'] ?? null,
                    'address' => $params['guarantor_address'] ?? null,
                    'phone' => $params['guarantor_phone'] ?? null,
                    'email' => $params['guarantor_email'] ?? null,
                ];

                $guarantor = Guarantor::create($guarantorData);
                $guarantorId = $guarantor->id;
            }


            $clientData = [
                'name' => $params['name'],
                'dni' => $params['dni'],
                'address' => $params['address'],
                'geolocation' => $params['geolocation'],
                'phone' => $params['phone'],
                'email' => $params['email'] ?? null,
                'company_name' => $params['company_name'] ?? null,
                'guarantor_id' => $guarantorId,
                'seller_id' => $params['seller_id'],
                'routing_order' => $params['routing_order']
            ];

            $client = Client::create($clientData);


            if (!empty($params['credit_value'])) {
                $creditData = [
                    'client_id' => $client->id,
                    'guarantor_id' => $guarantorId,
                    'seller_id' => $params['seller_id'],
                    'credit_value' => $params['credit_value'],
                    'total_interest' => $params['interest_rate'] ?? 0,
                    'number_installments' => $params['installment_count'] ?? 0,
                    'payment_frequency' => $params['payment_frequency'] ?? '',
                    'first_quota_date' => $params['first_installment_date'] ?? null,
                    'excluded_days' => json_encode($params['excluded_days'] ?? []),
                    'micro_insurance_percentage' => $params['micro_insurance_percentage'] ?? null,
                    'micro_insurance_amount' => $params['micro_insurance_amount'] ?? null,
                    'status' => 'Vigente'
                ];

                $credit = Credit::create($creditData);

                $totalAmount = $credit->credit_value + $credit->total_interest;
                $quotaAmount = (($credit->credit_value * $credit->total_interest / 100) + $credit->credit_value) / $credit->number_installments;

                $dueDate = Carbon::parse($credit->first_quota_date);
                $excludedDayNames = json_decode($credit->excluded_days, true) ?? [];

                $dayMap = [
                    'Domingo' => 0,
                    'Lunes' => 1,
                    'Martes' => 2,
                    'Miércoles' => 3,
                    'Jueves' => 4,
                    'Viernes' => 5,
                    'Sábado' => 6
                ];

                $excludedDayNumbers = [];
                foreach ($excludedDayNames as $dayName) {
                    if (isset($dayMap[$dayName])) {
                        $excludedDayNumbers[] = $dayMap[$dayName];
                    }
                }

                while (in_array($dueDate->dayOfWeek, $excludedDayNumbers)) {
                    $dueDate->addDay();
                }

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
                                while (in_array($dueDate->dayOfWeek, $excludedDayNumbers)) {
                                    $dueDate->addDay();
                                }
                                break;
                            case 'Semanal':
                                $dueDate->addWeek();
                                break;
                            case 'Quincenal':
                                $dueDate->addDays(15);
                                break;
                            case 'Mensual':
                                $dueDate->addMonth();
                                break;
                            default:
                                $dueDate->addMonth();
                        }
                    }
                }
            }

            if ($request->has('images')) {
                $images = $request->input('images');
                foreach ($images as $index => $imageData) {
                    $imageFile = $request->file("images.{$index}.file");

                    $imagePath = Helper::uploadFile($imageFile, 'clients');

                    $client->images()->create([
                        'path' => $imagePath,
                        'type' => $imageData['type']
                    ]);
                }
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente creado con éxito',
                'data' => $client,
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al crear el cliente', 500);
        }
    }



    public function update(ClientRequest $request, $clientId)
    {
        try {
            $client = Client::find($clientId);
            if (!$client) {
                return $this->errorNotFoundResponse('Cliente no encontrado');
            }

            $params = $request->validated();

            $client->update($params);
            if ($request->has('images')) {
                $validationResponse = $this->validateImages($request);
                if ($validationResponse !== true) {
                    return $validationResponse;
                }

                $client->images()->delete();

                $images = $request->input('images');
                foreach ($images as $index => $imageData) {
                    $imageFile = $request->file("images.{$index}.file");
                    $imagePath = Helper::uploadFile($imageFile, 'clients');
                    $client->images()->create([
                        'path' => $imagePath,
                        'type' => $imageData['type']
                    ]);
                }
            }

            if ($request->has('guarantors_ids')) {
                $client->guarantors()->sync($request->input('guarantors_ids'));
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente actualizado con éxito',
                'data' => $client
            ]);
        } catch (\Exception $e) {
            if (isset($uploadedImagePaths)) {
                foreach ($uploadedImagePaths as $path) {
                    Helper::deleteFile($path);
                }
            }
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al actualizar el cliente', 500);
        }
    }

    private function validateImages($request)
    {
        $images = $request->all()['images'];
        $profileCount = 0;
        $galleryCount = 0;

        foreach ($images as $index => $imageData) {
            if (!isset($imageData['file']) || !isset($imageData['type'])) {
                return $this->errorResponse('Cada imagen debe contener un archivo y un tipo ("profile" o "gallery").', 400);
            }



            if ($imageData['type'] === 'profile') {
                $profileCount++;
            } elseif ($imageData['type'] === 'gallery') {
                $galleryCount++;
            }

            if ($profileCount > 1) {
                return $this->errorResponse('Solo se permite una foto de perfil.', 400);
            }

            if ($galleryCount > 4) {
                return $this->errorResponse('Solo se permiten hasta 4 imágenes en la galería.', 400);
            }

            $imageFile = $request->file("images.{$index}.file");

            if ($imageFile->getSize() > 2 * 1024 * 1024) {
                return $this->errorResponse("La imagen {$index} excede 2MB", 400);
            }

            if (!$imageFile) {
                return $this->errorResponse('No se encontró la imagen en la solicitud.', 400);
            }

            /*    if (!$imageFile instanceof \UploadedFile) {
                return $this->errorResponse('Formato incorrecto de imágenes.', 400);
            } */

            if (!in_array($imageData['type'], ['profile', 'gallery'])) {
                return $this->errorResponse('El tipo de imagen es requerido y debe ser "profile" o "gallery".', 400);
            }
        }

        return true;
    }

    public function delete($clientId)
    {
        try {
            $client = Client::find($clientId);

            if ($client == null) {
                return $this->errorNotFoundResponse('Cliente no encontrado');
            }

            $client->guarantors()->detach();

            $client->images()->each(function ($image) {
                $image->delete();
            });

            $client->delete();

            return $this->successResponse([
                'success' => true,
                'message' => "Cliente eliminado con éxito",
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al eliminar el cliente', 500);
        }
    }


    public function index(
        string $search,
        int $perpage,
        string $orderBy = 'created_at',
        string $orderDirection = 'desc'
    ) {
        try {
            $user = Auth::user();
            $seller = $user->seller;

            $clientsQuery = Client::with(['guarantors', 'images', 'credits', 'seller', 'seller.city'])
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('dni', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });

            if ($user->role_id == 5 && $seller) {
                $clientsQuery->where('seller_id', $seller->id);
            }

            $validOrderDirections = ['asc', 'desc'];
            $orderDirection = in_array(strtolower($orderDirection), $validOrderDirections)
                ? $orderDirection
                : 'desc';

            $clientsQuery->orderBy($orderBy, $orderDirection);

            $clients = $clientsQuery->paginate($perpage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Clientes encontrados',
                'data' => $clients
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los clientes', 500);
        }
    }

    public function getClientsBySeller($sellerId, $search, $perpage)
    {
        try {
            return Client::with(['guarantors', 'images', 'credits', 'seller', 'seller.city'])
                ->where('seller_id', $sellerId)
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('dni', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                ->orderBy('created_at', 'desc')
                ->paginate($perpage);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            throw $e;
        }
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
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener el total de clientes', 500);
        }
    }



    public function getClientsSelect(string $search = '')
    {
        try {
            $clients = Client::where('name', 'like', "%{$search}%")
                ->orWhere('dni', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->select('id', 'name')
                ->get();

            if ($clients->isEmpty()) {
                return $this->errorNotFoundResponse('No se encontraron clientes');
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Clientes encontrados',
                'data' => $clients
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los clientes', 500);
        }
    }

    public function show($clientId)
    {
        try {
            $client = Client::with([
                'guarantors',
                'images',
                'seller',
                'seller.city',
                'credits' => function ($query) {
                    $query->orderBy('created_at', 'desc');
                },
                'credits.installments'
            ])->find($clientId);


            /* $client = Client::with(['credits.guarantor', 'credits.installments', 'images'])->find($clientId); */

            // Verificar si el cliente no existe
            if (!$client) {
                return $this->errorNotFoundResponse('Cliente no encontrado');
            }

            $images = $client->images->map(function ($image) {
                return [
                    'id' => $image->id,
                    'path' => $image->path,
                    'type' => $image->type
                ];
            });

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente encontrado',
                'data' => $client
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
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
        string $orderDirection = 'desc',
    ) {
        $user = Auth::user();
        $seller = $user->seller;

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

        $clientsQuery = Client::query()
            ->select('clients.*', 'clients.routing_order')
            ->selectSub('
            CASE 
                WHEN payment_priority.has_overdue = 1 THEN 1
                WHEN payment_priority.has_pending = 1 THEN 2
                ELSE 3
            END', 'payment_priority')
            ->leftJoinSub($paymentPrioritySubquery, 'payment_priority', function ($join) {
                $join->on('clients.id', '=', 'payment_priority.client_id');
            })
            ->whereHas('credits', function ($query) {
                $query->where('status', '!=', 'liquidado');
            })
            ->with([
                'guarantors',
                'images',
                'credits' => function ($query) use ($frequency, $paymentStatus) {
                    $query->with(['installments', 'payments', 'payments.installments'])
                        ->where('status', '!=', 'liquidado');

                    if (!empty($frequency)) {
                        $query->where('payment_frequency', $frequency);
                    }

                    if ($paymentStatus === 'paid') {
                        $query->whereHas('payments', function ($q) {
                            $q->whereDate('payment_date', now()->toDateString())
                                ->whereIn('status', ['Pagado', 'Abonado']);
                        });
                    } elseif ($paymentStatus === 'unpaid') {
                        $query->whereDoesntHave('payments', function ($q) {
                            $q->whereDate('payment_date', now()->toDateString());
                        });
                    } elseif ($paymentStatus === 'notpaid') {
                        $query->whereHas('payments', function ($q) {
                            $q->whereDate('payment_date', now()->toDateString())
                                ->where('status', 'No pagado');
                        });
                    }
                },
                'seller',
                'seller.city'
            ]);


        if (!empty($frequency)) {
            $clientsQuery->whereHas('credits', function ($query) use ($frequency) {
                $query->where('payment_frequency', $frequency)
                    ->where('status', '!=', 'liquidado');
            });
        }

        if (!empty($paymentStatus)) {
            if ($paymentStatus === 'paid') {
                $clientsQuery->whereHas('credits.payments', function ($query) {
                    $query->whereDate('payment_date', now()->toDateString())
                        ->whereIn('status', ['Pagado', 'Abonado']);
                });
            } elseif ($paymentStatus === 'unpaid') {
                $clientsQuery->where(function ($query) {
                    $query->whereDoesntHave('credits.payments', function ($q) {
                        $q->whereDate('payment_date', now()->toDateString());
                    });
                });
            } elseif ($paymentStatus === 'notpaid') {
                $clientsQuery->whereHas('credits.payments', function ($query) {
                    $query->whereDate('payment_date', now()->toDateString())
                        ->where('status', 'No pagado');
                });
            }
        }

        if (!empty($search)) {
            $clientsQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('dni', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($filter !== 'all') {
            $clientsQuery->whereHas('credits', function ($query) use ($filter) {
                $query->where('status', $filter)
                    ->where('status', '!=', 'liquidado');
            });
        }



        if ($user->role_id == 5 && $seller) {
            $clientsQuery->where('seller_id', $seller->id);
        }

        $clientsQuery->orderBy('clients.routing_order', 'asc');

        /*  if ($orderBy === 'routing') {
            $clientsQuery->orderBy('payment_priority')
                ->orderBy('clients.name');
        } else {
            $validOrderDirections = ['asc', 'desc'];
            $orderDirection = in_array(strtolower($orderDirection), $validOrderDirections)
                ? $orderDirection
                : 'desc';

            $clientsQuery->orderBy($orderBy, $orderDirection);
        } */

        $clients = $clientsQuery->paginate($perpage, ['*'], 'page', $page);

        $creditIds = $clients->getCollection()
            ->pluck('credits')
            ->flatten()
            ->pluck('id')
            ->unique()
            ->values();

        $paymentSummary = collect();
        if ($creditIds->isNotEmpty()) {
            $paymentSummary = Payment::whereIn('credit_id', $creditIds)
                ->select(
                    'credit_id',
                    'status',
                    DB::raw('SUM(amount) as total_amount')
                )
                ->groupBy('credit_id', 'status')
                ->get()
                ->groupBy('credit_id');
        }

        $transformedItems = $clients->getCollection()->map(function ($client) use ($paymentSummary) {
            if ($client->credits) {
                $client->credits->transform(function ($credit) use ($paymentSummary) {
                    $summary = $paymentSummary->get($credit->id, collect());
                    foreach ($summary as $item) {
                        $credit->{$item->status} = $item->total_amount;
                    }

                    $credit->installment = $credit->installments;
                    unset($credit->installments);

                    return $credit;
                });
            }
            return $client;
        });

        $clients->setCollection($transformedItems);

        return response()->json([
            'success' => true,
            'message' => 'Clientes encontrados',
            'data' => $clients->items(),
            'pagination' => [
                'total' => $clients->total(),
                'per_page' => $clients->perPage(),
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
            ]
        ]);
    }

public function getCollectionSummary(string $date = null)
{
    // Usar fecha actual si no se proporciona
    $date = $date ?: now()->format('Y-m-d');
    
    $user = Auth::user();
    $seller = $user->seller;
    $sellerId = $seller ? $seller->id : null;
    $userId = $user->id;

    // 1. TOTAL ESPERADO
    $expectedQuery = DB::table('installments')
        ->selectRaw('COALESCE(SUM(quota_amount), 0) as total_expected')
        ->join('credits', 'installments.credit_id', '=', 'credits.id')
        ->join('clients', 'credits.client_id', '=', 'clients.id')
        ->where('credits.status', '!=', 'liquidado')
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

    // 2. PAGOS DEL DÍA
    $todayPaymentsQuery = DB::table('payments')
        ->selectRaw('
            COALESCE(SUM(payments.amount), 0) as total_collected_today,
            COALESCE(SUM(CASE WHEN payments.status = "Pagado" THEN payments.amount ELSE 0 END), 0) as total_paid_today,
            COALESCE(SUM(CASE WHEN payments.status = "Abonado" THEN payments.amount ELSE 0 END), 0) as total_deposits_today,
            COUNT(DISTINCT credits.client_id) as clients_paid_today
        ')
        ->join('credits', 'payments.credit_id', '=', 'credits.id')
        ->join('clients', 'credits.client_id', '=', 'clients.id')
        ->whereDate('payments.payment_date', $date)
        ->whereIn('payments.status', ['Pagado', 'Abonado'])
        ->where('credits.status', '!=', 'liquidado');

    if ($sellerId) {
        $todayPaymentsQuery->where('credits.seller_id', $sellerId);
    }
    $todayPayments = $todayPaymentsQuery->first();

    // 3. PAGOS NO PAGADOS HOY
    $todayUnpaidPaymentsQuery = DB::table('payments')
        ->selectRaw('COALESCE(SUM(installments.quota_amount), 0) as total_unpaid_today')
        ->join('payment_installments', 'payments.id', '=', 'payment_installments.payment_id')
        ->join('installments', 'payment_installments.installment_id', '=', 'installments.id')
        ->join('credits', 'installments.credit_id', '=', 'credits.id')
        ->join('clients', 'credits.client_id', '=', 'clients.id')
        ->whereDate('payments.payment_date', $date)
        ->where('payments.status', 'No Pagado')
        ->where('credits.status', '!=', 'liquidado');

    if ($sellerId) {
        $todayUnpaidPaymentsQuery->where('credits.seller_id', $sellerId);
    }
    $todayUnpaidPayments = $todayUnpaidPaymentsQuery->first();

    // 4. ABONOS HOY
    $todayAbonosQuery = DB::table('payments')
        ->selectRaw('COALESCE(SUM(payments.amount), 0) as total_deposits_today')
        ->join('credits', 'payments.credit_id', '=', 'credits.id')
        ->join('clients', 'credits.client_id', '=', 'clients.id')
        ->whereDate('payments.payment_date', $date)
        ->where('payments.status', 'Abonado');

    if ($sellerId) {
        $todayAbonosQuery->where('credits.seller_id', $sellerId);
    }
    $todayAbonos = $todayAbonosQuery->first();

    // 5. RECAUDACIÓN DE CUOTAS VENCIDAS HOY
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
        ->where(function ($query) use ($date) {
            $query->whereDate('installments.due_date', $date)
                ->orWhere(function ($q) use ($date) {
                    $q->where('installments.status', 'Pendiente')
                        ->whereDate('due_date', '<', $date);
                });
        })
        ->whereIn('payments.status', ['Pagado', 'Abonado'])
        ->where('credits.status', '!=', 'liquidado');

    if ($sellerId) {
        $todayDueCollectedQuery->where('credits.seller_id', $sellerId);
    }
    $todayDueCollected = $todayDueCollectedQuery->first();

    // 6. ESTADÍSTICAS DE CLIENTES
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
            $join->on('installments.id', '=', 'payment_installments.installment_id');
        })
        ->leftJoin('payments', function ($join) {
            $join->on('payment_installments.payment_id', '=', 'payments.id')
                ->whereIn('payments.status', ['Pagado', 'Abonado']);
        })
        ->where(function ($query) use ($date) {
            $query->whereDate('installments.due_date', $date)
                ->orWhere(function ($q) use ($date) {
                    $q->where('installments.status', 'Pendiente')
                        ->whereDate('installments.due_date', '<', $date);
                });
        })
        ->where('credits.status', '!=', 'liquidado');

    if ($sellerId) {
        $clientsQuery->where('credits.seller_id', $sellerId);
    }
    $clientCounts = $clientsQuery->first();

    // 7. CLIENTES ATENDIDOS HOY
    $attendedTodayQuery = DB::table('payments')
        ->selectRaw('COUNT(DISTINCT credits.client_id) as attended_today')
        ->leftJoin('payment_installments', 'payments.id', '=', 'payment_installments.payment_id')
        ->leftJoin('installments', 'payment_installments.installment_id', '=', 'installments.id')
        ->leftJoin('credits', function ($join) {
            $join->on('installments.credit_id', '=', 'credits.id')
                ->orOn('payments.credit_id', '=', 'credits.id');
        })
        ->whereDate('payments.created_at', $date)
        ->where(function ($query) {
            $query->where('credits.status', '!=', 'liquidado')
                ->orWhereNull('credits.status');
        });

    if ($sellerId) {
        $attendedTodayQuery->where('credits.seller_id', $sellerId);
    }
    $attendedToday = $attendedTodayQuery->value('attended_today') ?? 0;

    // 8. CLIENTES PENDIENTES HOY
    $pendingTodayQuery = DB::table('clients')
        ->selectRaw('COUNT(DISTINCT clients.id) as pending_today')
        ->join('credits', 'clients.id', '=', 'credits.client_id')
        ->join('installments', 'credits.id', '=', 'installments.credit_id')
        ->where('credits.status', '!=', 'liquidado')
        ->whereDate('installments.due_date', $date)
        ->whereNotExists(function ($query) use ($date) {
            $query->select(DB::raw(1))
                ->from('payments')
                ->where(function ($q) {
                    $q->whereColumn('payments.credit_id', 'credits.id')
                        ->orWhereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('payment_installments')
                                ->join('installments as i', 'payment_installments.installment_id', '=', 'i.id')
                                ->whereColumn('i.credit_id', 'credits.id')
                                ->whereColumn('payment_installments.payment_id', 'payments.id');
                        });
                })
                ->whereDate('payments.created_at', $date);
        });

    if ($sellerId) {
        $pendingTodayQuery->where('credits.seller_id', $sellerId);
    }
    $pendingToday = $pendingTodayQuery->value('pending_today') ?? 0;

    // 9. CRÉDITOS ACTIVOS CREADOS HOY
    $activeCreditsTodayQuery = DB::table('credits')
        ->selectRaw('COUNT(credits.id) as total_active_credits_today')
        ->whereDate('credits.created_at', $date)
        ->where('credits.status', 'Vigente');

    if ($sellerId) {
        $activeCreditsTodayQuery->where('credits.seller_id', $sellerId);
    }
    $activeCreditsToday = $activeCreditsTodayQuery->first();

    // 10. GASTOS DEL DÍA
    $dailyExpensesQuery = DB::table('expenses')
        ->selectRaw('COALESCE(SUM(expenses.value), 0) as total_expenses_today')
        ->whereDate('expenses.created_at', $date);

    if ($sellerId) {
        $dailyExpensesQuery->where('expenses.user_id', $userId);
    }
    $dailyExpenses = $dailyExpensesQuery->first();

    // 11. CLIENTES EN MORA HOY
    $defaultedClientsCountQuery = DB::table('clients')
        ->join('credits', 'clients.id', '=', 'credits.client_id')
        ->join('installments', 'credits.id', '=', 'installments.credit_id')
        ->join('payment_installments', 'installments.id', '=', 'payment_installments.installment_id')
        ->join('payments', 'payment_installments.payment_id', '=', 'payments.id')
        ->whereDate('payments.created_at', $date)
        ->where(function ($query) {
            $query->where('payments.status', '!=', 'Pagado')
                ->where('payments.status', '!=', 'Abonado');
        })
        ->where('credits.status', '!=', 'liquidado');

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
}
