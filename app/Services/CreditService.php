<?php

namespace App\Services;

use App\Helpers\Helper;
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
                $query->where(function ($q) use ($search) {
                    $q->whereHas('client', function ($query) use ($search) {
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
