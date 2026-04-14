<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionRemindersService;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

class CollectionRemindersController extends Controller
{
    use ResolvesCollectionCompany;

    public function __construct(private readonly CollectionRemindersService $service)
    {
    }

    public function upcoming(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $daysAhead = (int) $request->input('days_ahead', 1);
        return response()->json($this->service->getUpcoming($companyId, $daysAhead));
    }

    public function markSent(Request $request, int $installmentId)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->markAsSent(
            $companyId,
            $installmentId,
            $request->input('channel', 'whatsapp'),
            $request->input('notes')
        );
    }

    public function history(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $limit = (int) $request->input('limit', 50);
        return response()->json(['reminders' => $this->service->getHistory($companyId, $limit)]);
    }
}
