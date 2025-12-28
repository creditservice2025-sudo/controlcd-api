<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $plans = Plan::all();
        return response()->json($plans);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'billing_cycle' => 'required|in:weekly,biweekly,monthly,yearly',
            'duration_days' => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $plan = Plan::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Plan creado exitosamente',
            'data' => $plan
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $plan = Plan::findOrFail($id);
        return response()->json($plan);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $plan = Plan::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'price' => 'sometimes|required|numeric|min:0',
            'billing_cycle' => 'sometimes|required|in:weekly,biweekly,monthly,yearly',
            'duration_days' => 'sometimes|required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $plan->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Plan actualizado exitosamente',
            'data' => $plan
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $plan = Plan::findOrFail($id);
        
        // Verificar si hay empresas usando este plan
        if ($plan->companies()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el plan porque hay empresas asociadas a él.'
            ], 409);
        }

        $plan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Plan eliminado exitosamente'
        ]);
    }
}
