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
                $firstQuotaDate = $params['first_installment_date'] ?? null;
                $paymentFrequency = $params['payment_frequency'] ?? '';
                $excludedDays = $params['excluded_days'] ?? [];

                if (!$firstQuotaDate) {
                    $today = now();
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
                            $firstQuotaDate = $today->addMonth()->format('Y-m-d');
                            break;
                        default:
                            $firstQuotaDate = $today->addDay()->format('Y-m-d');
                    }
                }

                $creditData = [
                    'client_id' => $client->id,
                    'guarantor_id' => $guarantorId,
                    'seller_id' => $params['seller_id'],
                    'credit_value' => $params['credit_value'],
                    'total_interest' => $params['interest_rate'] ?? 0,
                    'number_installments' => $params['installment_count'] ?? 0,
                    'payment_frequency' => $paymentFrequency,
                    'first_quota_date' => $firstQuotaDate,
                    'excluded_days' => json_encode($excludedDays),
                    'micro_insurance_percentage' => $params['micro_insurance_percentage'] ?? null,
                    'micro_insurance_amount' => $params['micro_insurance_amount'] ?? null,
                    'status' => 'Vigente'
                ];

                $credit = Credit::create($creditData);

                $quotaAmount = (($credit->credit_value * $credit->total_interest / 100) + $credit->credit_value) / $credit->number_installments;

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

                \Log::info("Fecha primera cuota ajustada: " . $dueDate->format('Y-m-d'));

                for ($i = 1; $i <= $credit->number_installments; $i++) {
                    \Log::info("Creando cuota $i para fecha: " . $dueDate->format('Y-m-d'));

                    Installment::create([
                        'credit_id' => $credit->id,
                        'quota_number' => $i,
                        'due_date' => $dueDate->format('Y-m-d'),
                        'quota_amount' => round($quotaAmount, 2),
                        'status' => 'Pendiente'
                    ]);

                    // Calcular siguiente fecha solo si no es la última cuota
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
                                $dueDate->addMonth();
                                break;
                            default:
                                $dueDate->addMonth();
                        }

                        $dueDate = $adjustForExcludedDays($dueDate);
                    }
                }
            }

            if ($request->has('images')) {
                $images = $request->input('images');
                foreach ($images as $index => $imageData) {
                    $imageFile = $request->file("images.{$index}.file");
                    $imageType = $imageData['type'];

                    $imagePath = Helper::uploadFile($imageFile, 'clients');

                    $client->images()->create([
                        'path' => $imagePath,
                        'type' => $imageType
                    ]);
                }
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente creado con éxito',
                'data' => $client,
            ]);
        } catch (\Exception $e) {
            \Log::error("Error al crear cliente: " . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return $this->errorResponse('Error al crear el cliente: ' . $e->getMessage(), 500);
        }
    }



    public function update(ClientRequest $request, $clientId)
    {
        DB::beginTransaction();

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
                    DB::rollBack();
                    return $validationResponse;
                }

                $images = $request->input('images');
                foreach ($images as $index => $imageData) {
                    $imageFile = $request->file("images.{$index}.file");
                    $imageType = $imageData['type'];

                    $existingImage = $client->images()->where('type', $imageType)->first();
                    if ($existingImage) {
                        Helper::deleteFile($existingImage->path);
                        $existingImage->delete();
                    }

                    $imagePath = Helper::uploadFile($imageFile, 'clients');
                    $client->images()->create([
                        'path' => $imagePath,
                        'type' => $imageType
                    ]);
                }
            }

            if ($request->has('guarantors_ids')) {
                $client->guarantors()->sync($request->input('guarantors_ids'));
            }

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente actualizado con éxito',
                'data' => $client
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
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

            if (!in_array($imageData['type'], ['profile', 'gallery', 'money_in_hand', 'business', 'document'])) {
                return $this->errorResponse('El tipo de imagen es requerido y debe ser "profile", "gallery", "money_in_hand", "business" o "document".', 400);
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
        $search = '',
        int $perpage = 5,
        string $orderBy = 'created_at',
        string $orderDirection = 'desc'
    ) {
        try {
            $search = (string) $search;

            $user = Auth::user();
            $seller = $user->seller;

            $clientsQuery = Client::with(['guarantors', 'images', 'credits', 'seller', 'seller.city']);

            if (!empty(trim($search))) {
                $clientsQuery->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('dni', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($user->role_id == 5 && $seller) {
                $clientsQuery->where('seller_id', $seller->id);
            }

            $validOrderDirections = ['asc', 'desc'];
            $orderDirection = in_array(strtolower($orderDirection), $validOrderDirections)
                ? $orderDirection
                : 'desc';

            $clientsQuery->orderBy($orderBy, $orderDirection);

            $clients = $clientsQuery->get();

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

    public function getClientsBySeller($sellerId, $search)
    {
        try {
            // Se elimina el parámetro de paginación $perpage
            return Client::with(['guarantors', 'images', 'credits', 'seller', 'seller.city'])
                ->where('seller_id', $sellerId)
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('dni', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                ->orderBy('created_at', 'desc')
                ->get();
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            throw $e;
        }
    }

    public function getAllClientsBySeller($sellerId, $search = '', $date = null)
    {
        try {
            $filterDate = $date ? Carbon::parse($date) : Carbon::today();

            $clients = Client::with([
                'guarantors',
                'images',
                'credits' => function ($query) {
                    $query->withCount([
                        'installments as paid_installments_count' => function ($query) {
                            $query->where('status', 'Pagado');
                        }
                    ]);
                },
                'credits.payments.installments.installment',
                'seller',
                'seller.city'
            ])
                ->where('seller_id', $sellerId)
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('dni', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                ->orderBy('routing_order', 'asc')
                ->get();

            $clients->each(function ($client) use ($filterDate) {
                $client->distantPayments = collect();
                $client->todayPayments = collect();

                foreach ($client->credits as $credit) {
                    $credit->pending_installments = $credit->number_installments - $credit->paid_installments_count;

                    $datePayments = $credit->payments->filter(function ($payment) use ($filterDate) {
                        return $payment->payment_date &&
                            Carbon::parse($payment->payment_date)->isSameDay($filterDate);
                    });

                    foreach ($datePayments as $payment) {
                        $paidQuotaNumbers = $payment->installments->map(function ($installment) {
                            return $installment->installment->quota_number ?? 'N/A';
                        })->filter()->join(', ');

                        $paymentTime = ($payment->payment_date instanceof \DateTimeInterface)
                            ? $payment->payment_date->format('H:i:s')
                            : Carbon::parse($payment->payment_date)->format('H:i:s');

                        $paymentData = [
                            'client_id' => $client->id,
                            'client_name' => $client->name,
                            'credit_id' => $credit->id,
                            'payment_id' => $payment->id,
                            'amount' => $payment->amount,
                            'payment_date' => $payment->payment_date,
                            'payment_time' => $payment->created_at,
                            'installment' => $paidQuotaNumbers ?: "N/A",
                            'installment_details' => $payment->installments,
                            'latitude' => $payment->latitude,
                            'longitude' => $payment->longitude,
                            'paid_installments' => $credit->paid_installments_count,
                            'pending_installments' => $credit->pending_installments
                        ];

                        $client->todayPayments->push($paymentData);

                        if ($client->coordinates && isset($client->coordinates['latitude'], $client->coordinates['longitude'])) {
                            $clientLat = $client->coordinates['latitude'];
                            $clientLon = $client->coordinates['longitude'];

                            if ($payment->latitude && $payment->longitude) {
                                $distance = $this->calculateDistance(
                                    $clientLat,
                                    $clientLon,
                                    $payment->latitude,
                                    $payment->longitude
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
            Log::error($e->getMessage());
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
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener el total de clientes', 500);
        }
    }



    public function getClientsSelect(string $search = '')
    {
        try {

            $user = Auth::user();

            $clients = Client::where('name', 'like', "%{$search}%")
                ->orWhere('dni', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->select('id', 'name')
                ->get();

            if ($user->role_id == 5) {
                $seller = $user->seller;
                $clients->where('seller_id', $seller->id);
            }

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



    public function getClientDetails($clientId)
    {
        try {
            $client = Client::with([
                'credits' => function ($query) {
                    $query->with(['payments' => function ($q) {
                        $q->select('*') 
                          ->with(['installments.installment' => function ($innerQ) {
                              $innerQ->select('*');
                          }]);
                    }]);
                },
                'seller' => function ($query) {
                    $query->select('*'); 
                },
                'seller.city' => function ($query) {
                    $query->select('id', 'name');
                },
                'guarantors' => function ($query) {
                    $query->select('*');
                },
                'images' => function ($query) {
                    $query->select('*');
                }
            ])
            ->find($clientId);
    
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

        // Consulta principal de CRÉDITOS
        $creditsQuery = Credit::query()
            ->select('credits.*')
            ->join('clients', 'clients.id', '=', 'credits.client_id')
            ->selectSub('
            CASE 
                WHEN payment_priority.has_overdue = 1 THEN 1
                WHEN payment_priority.has_pending = 1 THEN 2
                ELSE 3
            END', 'payment_priority')
            ->leftJoinSub($paymentPrioritySubquery, 'payment_priority', function ($join) {
                $join->on('clients.id', '=', 'payment_priority.client_id');
            })
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
                $query->where('credits.status', '!=', 'liquidado')
                    ->orWhere(function ($q) {
                        $q->where('credits.status', 'liquidado')
                            ->whereDate('credits.updated_at', now()->toDateString());
                    });
            });

        // Aplicar filtros
        if (!empty($frequency)) {
            $creditsQuery->where('payment_frequency', $frequency);
        }

        if (!empty($paymentStatus)) {
            if ($paymentStatus === 'paid') {
                $creditsQuery->whereHas('payments', function ($query) {
                    $query->whereDate('payment_date', now()->toDateString())
                        ->whereIn('status', ['Pagado', 'Abonado']);
                });
            } elseif ($paymentStatus === 'unpaid') {
                $creditsQuery->whereDoesntHave('payments', function ($q) {
                    $q->whereDate('payment_date', now()->toDateString());
                });
            } elseif ($paymentStatus === 'notpaid') {
                $creditsQuery->whereHas('payments', function ($query) {
                    $query->whereDate('payment_date', now()->toDateString())
                        ->where('status', 'No pagado');
                });
            }
        }

        if (!empty($search)) {
            $creditsQuery->where(function ($query) use ($search) {
                $query->whereHas('client', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('dni', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        if ($filter !== 'all') {
            $creditsQuery->where('status', $filter);
        }

        if ($user->role_id == 5 && $seller) {
            $creditsQuery->whereHas('client', function ($query) use ($seller) {
                $query->where('seller_id', $seller->id);
            });
        }

        // Ordenación
        $creditsQuery->orderBy('clients.routing_order', 'asc');

        // Paginación de créditos
        $credits = $creditsQuery->paginate($perpage, ['*'], 'page', $page);

        // Resumen de pagos
        $paymentSummary = Payment::whereIn('credit_id', $credits->getCollection()->pluck('id'))
            ->select(
                'credit_id',
                'status',
                DB::raw('SUM(amount) as total_amount')
            )
            ->groupBy('credit_id', 'status')
            ->get()
            ->groupBy('credit_id');

        $transformedItems = $credits->getCollection()->map(function ($credit) use ($paymentSummary) {
            $summary = $paymentSummary->get($credit->id, collect());
            foreach ($summary as $item) {
                $credit->{$item->status} = $item->total_amount;
            }

            $credit->installment = $credit->installments;
            unset($credit->installments);

            $overdueInstallments = $credit->installment->filter(function ($installment) {
                return $installment->due_date < now()->toDateString() && $installment->status !== 'Pagado';
            });

            $totalInstallments = $credit->installment->count();
            $remainingInstallments = $credit->installment->filter(function ($installment) {
                return $installment->status !== 'Pagado';
            })->count();

            if ($overdueInstallments->count() > 0) {
                // El crédito tiene cuotas vencidas
                $credit->credit_status = 'Overdue';
            } elseif ($remainingInstallments <= 4 && $overdueInstallments->count() === 0) {
                // Faltan 4 o menos cuotas para terminar y no tiene cuotas vencidas
                $credit->credit_status = 'Renewal_pending';
            } elseif ($overdueInstallments->count() <= 2) {
                // El crédito está al día y tiene 2 o menos cuotas atrasadas
                $credit->credit_status = 'On_time';
            } else {
                // Cualquier otro caso por defecto, puedes ajustarlo si lo necesitas
                $credit->credit_status = 'Normal';
            }


            return $credit;
        });

        $credits->setCollection($transformedItems);

        return response()->json([
            'success' => true,
            'message' => 'Creditos encontrados',
            'data' => $credits->items(),
            'pagination' => [
                'total' => $credits->total(),
                'per_page' => $credits->perPage(),
                'current_page' => $credits->currentPage(),
                'last_page' => $credits->lastPage(),
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

        // 1. TOTAL ESPERADO (considerando soft deletes)
        $expectedQuery = DB::table('installments')
            ->selectRaw('COALESCE(SUM(quota_amount), 0) as total_expected')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->whereNull('installments.deleted_at')
            ->whereNull('credits.deleted_at')
            ->whereNull('clients.deleted_at')
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
            ->whereDate('payments.payment_date', $date)
            ->whereIn('payments.status', ['Pagado', 'Abonado'])
            ->where('credits.status', '!=', 'liquidado');

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
            ->whereDate('payments.payment_date', $date)
            ->where('payments.status', 'No Pagado')
            ->where('credits.status', '!=', 'liquidado');

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
            ->whereDate('payments.payment_date', $date)
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
            ->whereIn('payments.status', ['Pagado', 'Abonado'])
            ->where('credits.status', '!=', 'liquidado');

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
            })
            ->where('credits.status', '!=', 'liquidado');

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
            ->whereDate('payments.created_at', $date)
            ->where(function ($query) {
                $query->where('credits.status', '!=', 'liquidado')
                    ->orWhereNull('credits.status');
            });

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
            ->where('credits.status', '!=', 'liquidado')
            ->whereDate('installments.due_date', $date)
            ->whereNotExists(function ($query) use ($date) {
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
                    ->whereDate('payments.created_at', $date);
            });

        if ($sellerId) {
            $pendingTodayQuery->where('credits.seller_id', $sellerId);
        }
        $pendingToday = $pendingTodayQuery->value('pending_today') ?? 0;

        // 9. CRÉDITOS ACTIVOS CREADOS HOY (considerando soft deletes)
        $activeCreditsTodayQuery = DB::table('credits')
            ->selectRaw('COUNT(credits.id) as total_active_credits_today')
            ->whereNull('credits.deleted_at')
            ->whereDate('credits.created_at', $date)
            ->where('credits.status', 'Vigente');

        if ($sellerId) {
            $activeCreditsTodayQuery->where('credits.seller_id', $sellerId);
        }
        $activeCreditsToday = $activeCreditsTodayQuery->first();

        // 10. GASTOS DEL DÍA (considerando soft deletes)
        $dailyExpensesQuery = DB::table('expenses')
            ->selectRaw('COALESCE(SUM(expenses.value), 0) as total_expenses_today')
            ->whereNull('expenses.deleted_at')
            ->whereDate('expenses.created_at', $date);

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
