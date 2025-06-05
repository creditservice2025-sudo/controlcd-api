<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Guarantor;
use App\Models\Credit;
use App\Http\Requests\Credit\CreditRequest;
use Carbon\Carbon;

class CreditService
{
    use ApiResponse;

    public function create(CreditRequest $request)
    {
        try {
            $params = $request->validated();

            $client = Client::find($request->input('client_id'));
            $guarantor = Guarantor::find($request->input('guarantor_id'));

            if (!$client) {
               return $this->errorResponse('El cliente no existe.', 404);
            }

            $creditValue = $request->input('credit_value');
            $numberInstallments = $request->input('number_installments');
            $totalInterest = $request->input('total_interest') ? $request->input('total_interest') : 0;

            $quotaAmount = ($creditValue / $numberInstallments);
            $totalAmount = $quotaAmount * $numberInstallments;

            $params['total_amount'] = $creditValue;
            $params['remaining_amount'] = $creditValue;

            $firstQuotaDate = Carbon::createFromFormat('d-m-Y', $request->input('first_quota_date'))->format('Y-m-d');
            $params['first_quota_date'] = $firstQuotaDate;

            // Map payment_frequency to the correct format
            $paymentFrequencyMap = [
                'daily' => 'Diaria',
                'weekly' => 'Semanal',
                'biweekly' => 'Quincenal',
                'monthly' => 'Mensual'
            ];
            $params['payment_frequency'] = $paymentFrequencyMap[$request->input('payment_frequency')] ?? $request->input('payment_frequency');

            $credit = Credit::create($params);

            if ($client && $guarantor) {
                $guarantor->clients()->syncWithoutDetaching($client->getKey());
            }

            $this->generateInstallments($credit, $quotaAmount, $params['first_quota_date'], $params['payment_frequency'], $numberInstallments);

            return $this->successResponse([
                'success' => true,
                'message' => 'Crédito creado correctamente',
                'data' => $credit
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al crear el crédito');
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
            return 'texto';
            $credits = Credit::with(['client', 'route'])
            
            ->where(function ($query) use ($search) {
                $query->whereHas('client', function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                    ->orWhere('dni', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->paginate($perPage); 

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
            $query = Credit::with(['client', 'seller'])
                ->select('client_id', 'seller_id', DB::raw('count(*) as total_credits'), DB::raw('sum(credit_value) as total_credit_value'))
                ->groupBy('client_id', 'seller_id');

            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->whereHas('client', function($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('dni', 'like', "%{$search}%");
                    })
                    /* ->orWhereHas('route', function($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('sector', 'like', "%{$search}%");
                    }) */;
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

}
