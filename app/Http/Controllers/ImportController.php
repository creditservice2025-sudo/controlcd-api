<?php

namespace App\Http\Controllers;

use App\Services\ImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImportController extends Controller
{
    protected $importService;

    public function __construct(ImportService $importService)
    {
        $this->importService = $importService;
    }

    public function store(Request $request)
    {
        try {
            // Restriction: Permission based or Super-Admin/Admin roles
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
            }

            // Allow if user has permission OR is Role 1 (Super-Admin) or Role 2 (Admin)
            if (!$user->can('realizar_carga_masiva') && !in_array($user->role_id, [1, 2])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acceso denegado. No tiene permiso para realizar cargas masivas.'
                ], 403);
            }

            $request->validate([
                'file' => 'required|file|mimes:csv,txt,xlsx,xls',
                'seller_id' => 'required|exists:sellers,id'
            ]);

            if (!$request->hasFile('file')) {
                return response()->json(['success' => false, 'message' => 'No se proporcionó ningún archivo.'], 400);
            }

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            
            // Store temporarily
            $path = $file->storeAs('temp_imports', 'import_' . time() . '_' . $originalName);
            $fullPath = storage_path('app/' . $path);

            // Execute service based on extension
            if (in_array(strtolower($extension), ['xlsx', 'xls'])) {
                $result = $this->importService->importFromExcel($fullPath, $request->seller_id);
            } else {
                $result = $this->importService->importFromCsv($fullPath, $request->seller_id);
            }

            // Cleanup
            @unlink($fullPath);

            return response()->json([
                'success' => true,
                'message' => "Proceso completado. Éxitos: {$result['success']}, Errores: " . count($result['errors']),
                'details' => $result
            ]);

        } catch (\Exception $e) {
            Log::error("Import Controller Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error durante la importación: ' . $e->getMessage()
            ], 500);
        }
    }

    public function downloadTemplate()
    {
        return $this->importService->downloadExcelTemplate();
    }
}
