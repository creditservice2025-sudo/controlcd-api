<?php

namespace App\Services;

use App\Helpers\Helper;
use App\Traits\ApiResponse;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Guarantor;
use Illuminate\Support\Str;
use App\Models\Credit;
use App\Http\Requests\Credit\CreditRequest;
use App\Models\Installment;
use App\Models\Liquidation;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CreditService
{
    use ApiResponse;

    public function create(CreditRequest $request)
    {
        try {
            $params = $request->validated();
            \Log::info('Datos recibidos para crédito:', $params);

            // Calcular fecha de primera cuota si no se proporciona
            $firstQuotaDate = $params['first_installment_date'] ?? null;
            if (!$firstQuotaDate) {
                $today = now();
                switch ($params['payment_frequency']) {
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
                'client_id' => $params['client_id'],
                'seller_id' => $params['seller_id'],
                'guarantor_id' => $params['guarantor_id'] ?? null,
                'credit_value' => $params['credit_value'],
                'total_interest' => $params['interest_rate'],
                'number_installments' => $params['installment_count'],
                'payment_frequency' => $params['payment_frequency'],
                'excluded_days' => json_encode($params['excluded_days'] ?? []),
                'micro_insurance_percentage' => $params['micro_insurance_percentage'] ?? null,
                'micro_insurance_amount' => $params['micro_insurance_amount'] ?? null,
                'first_quota_date' => $firstQuotaDate,
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

                    // Ajustar la nueva fecha si cae en día excluido
                    $dueDate = $adjustForExcludedDays($dueDate);
                }
            }

            if ($request->has('images')) {
                $images = $request->input('images');
                foreach ($images as $index => $imageData) {
                    $imageFile = $request->file("images.{$index}.file");

                    $imagePath = Helper::uploadFile($imageFile, 'clients');

                    $credit->client->images()->create([
                        'path' => $imagePath,
                        'type' => $imageData['type']
                    ]);
                }
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Crédito creado con éxito',
                'data' => [
                    'credit' => $credit,
                    'first_quota_date' => $credit->first_quota_date,
                    'adjusted_first_date' => $dueDate->format('Y-m-d'),
                    'total_installments' => $credit->number_installments
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error("Error al crear crédito: " . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return $this->errorResponse('Error al crear el crédito: ' . $e->getMessage(), 500);
        }
    }

    public function delete($creditId)
    {
        try {
            DB::beginTransaction();

            $credit = Credit::with('seller')->find($creditId);

            if (!$credit) {
                DB::rollBack();
                return $this->errorResponse('El crédito no existe.', 404);
            }

            $liquidationExists = Liquidation::where('seller_id', $credit->seller_id)
                ->whereDate('created_at', Carbon::today())
                ->exists();

            if ($liquidationExists) {
                DB::rollBack();
                return $this->errorResponse(
                    'No se puede eliminar el crédito. El vendedor ya tiene una liquidación registrada para el día de hoy.',
                    403
                );
            }

            if ($credit->payments) {
                $credit->payments()->forceDelete();
            }
            $credit->installments()->forceDelete();

            $credit->forceDelete();

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Crédito eliminado correctamente',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error al eliminar el crédito con ID {$creditId}: " . $e->getMessage());
            return $this->errorResponse('Error al eliminar el crédito: ' . $e->getMessage(), 500);
        }
    }

    public function index(string $search, int $perPage)
    {
        try {
            $user = Auth::user();
            $seller = $user->seller;

            $creditsQuery = Credit::with(['client', 'route'])
                ->where(function ($query) use ($search) {
                    $query->whereHas('client', function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('dni', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                });

            if ($user->role_id == 5 && $seller) {
                $creditsQuery->whereHas('client', function ($query) use ($seller) {
                    $query->where('seller_id', $seller->id);
                });
            }

            $credits = $creditsQuery->paginate($perPage);

            return $this->successResponse([
                'success' => true,
                'data' => $credits,
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener los créditos');
        }
    }

    public function show($creditId)
    {
        try {
            $credit = Credit::with(['client', 'guarantor', 'route'])->find($creditId);

            if (!$credit) {
                return $this->errorResponse('El crédito no existe.', 404);
            }

            return $this->successResponse([
                'success' => true,
                'data' => $credit
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener el crédito');
        }
    }

    public function update(CreditRequest $request, $creditId)
    {
        try {
            $credit = Credit::find($creditId);

            if (!$credit) {
                return $this->errorResponse('El crédito no existe.', 404);
            }

            $params = $request->validated();
            $credit->update($params);

            return $this->successResponse([
                'success' => true,
                'message' => 'Crédito actualizado correctamente',
                'data' => $credit
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al actualizar el crédito');
        }
    }

    protected function generateInstallments(Credit $credit, float $quotaAmount, string $firstQuotaDate, string $paymentFrequency, int $numberInstallments)
    {

        try {
            $dueDate = Carbon::parse($firstQuotaDate);

            for ($i = 1; $i <= $numberInstallments; $i++) {
                $credit->installments()->create([
                    'quota_number' => $i,
                    'due_date' => $dueDate->toDateString(),
                    'quota_amount' => $quotaAmount,
                    'status' => 'Pendiente',
                ]);

                switch ($paymentFrequency) {
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
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al generar las cuotas');
        }
    }

    public function getClientCredits(string $search, int $perPage)
    {
        try {
            $user = Auth::user();
            $seller = $user->seller;

            $query = Credit::with(['client', 'seller'])
                ->select(
                    'client_id',
                    'seller_id',
                    DB::raw('count(*) as total_credits'),
                    DB::raw('sum(credit_value) as total_credit_value')
                )
                ->groupBy('client_id', 'seller_id');


            if ($user->role_id == 5 && $seller) {
                $query->whereHas('client', function ($q) use ($seller) {
                    $q->where('seller_id', $seller->id);
                });
            }


            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('client', function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('dni', 'like', "%{$search}%");
                    });
                });
            }

            $paginator = $query->paginate($perPage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Créditos de clientes obtenidos correctamente',
                'data' => $paginator
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener los créditos del cliente');
        }
    }

    public function getCredits(string $clientId, $page = 1, $perPage = 5, $search = null)
    {
        try {
            $query = Credit::query()
                ->where('client_id', $clientId)
                ->with(['client', 'seller', 'installments', 'payments'])
                ->orderBy('created_at', 'desc');

            $credits = $query->paginate($perPage, ['*'], 'page', $page);

            $paymentSummary = Payment::whereIn('credit_id', $credits->pluck('id'))
                ->select(
                    'credit_id',
                    'status',
                    DB::raw('SUM(amount) as total_amount')
                )
                ->groupBy('credit_id', 'status')
                ->get()
                ->groupBy('credit_id');

            $creditsWithSummary = $credits->getCollection()->map(function ($credit) use ($paymentSummary) {
                $summary = $paymentSummary->get($credit->id, collect());

                foreach ($summary as $item) {
                    $credit->{$item->status} = $item->total_amount;
                }

                return $credit;
            });

            $credits->setCollection($creditsWithSummary);

            return $this->successResponse([
                'data' => $credits->items(),
                'pagination' => [
                    'total' => $credits->total(),
                    'current_page' => $credits->currentPage(),
                    'per_page' => $credits->perPage(),
                    'last_page' => $credits->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener los créditos del cliente');
        }
    }

    public function getSellerCreditsByDate(int $sellerId, Request $request, int $perpage)
    {
        try {
            $creditsQuery = Credit::with(['client', 'installments', 'payments'])
                ->where('seller_id', $sellerId);

            // Lógica de filtro de fechas
            if ($request->has('start_date') && $request->has('end_date')) {
                $startDate = Carbon::parse($request->get('start_date'))->startOfDay();
                $endDate = Carbon::parse($request->get('end_date'))->endOfDay();
                $creditsQuery->whereBetween('created_at', [$startDate, $endDate]);
            } elseif ($request->has('date')) {
                $filterDate = Carbon::parse($request->get('date'))->toDateString();
                $creditsQuery->whereDate('created_at', $filterDate);
            } else {
                $creditsQuery->whereDate('created_at', Carbon::today()->toDateString());
            }

            $credits = $creditsQuery->paginate($perpage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Créditos obtenidos correctamente para el vendedor y fecha(s) especificadas',
                'data' => $credits
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los créditos del vendedor: ' . $e->getMessage(), 500);
        }
    }
}
