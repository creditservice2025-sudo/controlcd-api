<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class HolidayController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Holiday::with('country');

        if ($request->has('country_id')) {
            $query->where('country_id', $request->country_id);
        }

        $holidays = $query->orderBy('date', 'desc')->get();

        return $this->successResponse($holidays);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'country_id' => 'required|exists:countries,id',
            'date' => 'required|date',
            'description' => 'nullable|string|max:255',
        ]);

        $holiday = Holiday::create($validated);

        return $this->successResponse($holiday, 'Feriado creado correctamente', 201);
    }

    public function show(Holiday $holiday)
    {
        return $this->successResponse($holiday->load('country'));
    }

    public function update(Request $request, Holiday $holiday)
    {
        $validated = $request->validate([
            'country_id' => 'required|exists:countries,id',
            'date' => 'required|date',
            'description' => 'nullable|string|max:255',
        ]);

        $holiday->update($validated);

        return $this->successResponse($holiday, 'Feriado actualizado correctamente');
    }

    public function destroy(Holiday $holiday)
    {
        $holiday->delete();

        return $this->successResponse(null, 'Feriado eliminado correctamente');
    }
}
