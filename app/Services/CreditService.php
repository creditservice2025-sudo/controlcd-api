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
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class CreditService
{
    use ApiResponse;

    public function create(CreditRequest $request)
    {
        try {
            $params = $request->validated();
            \Log::info('Datos recibidos para crédito:', $params);




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
                'first_quota_date' => $params['first_installment_date'] ?? now()->addDay()->toDateString(),
            ];

            $credit = Credit::create($creditData);

            $totalAmount = $credit->credit_value + $credit->total_interest;
            $quotaAmount = (($credit->credit_value * $credit->total_interest / 100) + $credit->credit_value)  / $credit->number_installments;

            $dueDate = Carbon::parse($credit->first_quota_date);
            $excludedDays = json_decode($credit->excluded_days, true) ?? [];

            for ($i = 1; $i <= $credit->number_installments; $i++) {
                // Ajustar fecha si cae en día excluido
                while (in_array($dueDate->dayOfWeek, $excludedDays)) {
                    $dueDate->addDay();
                }

                Installment::create([
                    'credit_id' => $credit->id,
                    'quota_number' => $i,
                    'due_date' => $dueDate->format('Y-m-d'),
                    'quota_amount' => round($quotaAmount, 2),
                    'status' => 'Pendiente'
                ]);

                switch ($credit->payment_frequency) {
                    case 'Diario':
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
                'data' => $credit
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al crear el crédito', 500);
        }
    }

    public function delete($creditId)
    {
        try {
            $credit = Credit::find($creditId);
            $credit->installments()->forceDelete();
            $credit->forceDelete();
            return $this->successResponse([
                'success' => true,
                'message' => 'Crédito eliminado correctamente',
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al eliminar el crédito');
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


}
