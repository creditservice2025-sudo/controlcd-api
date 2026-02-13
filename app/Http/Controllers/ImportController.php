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
                'seller_id' => 'required|exists:sellers,id',
                'selected_dnis' => 'nullable|array',
                'is_selective' => 'nullable|boolean'
            ]);

            if (!$request->hasFile('file')) {
                return response()->json(['success' => false, 'message' => 'No se proporcionó ningún archivo.'], 400);
            }

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            
            // If is_selective is true, we pass the array (even if empty). If false, we pass null to import all.
            $isSelective = $request->boolean('is_selective', false);
            $selectedDnis = $isSelective ? $request->input('selected_dnis', []) : null;
            
            // Sanitize filename: remove spaces and special characters
            $sanitizedName = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $originalName);
            $sanitizedName = preg_replace('/_+/', '_', $sanitizedName); // Replace multiple underscores with single
            
            // Store temporarily with sanitized name
            $tmpFilename = 'import_' . time() . '_' . $sanitizedName;
            Log::info("ImportController: Attempting to store file", [
                'original_name' => $originalName,
                'sanitized_name' => $sanitizedName,
                'tmp_filename' => $tmpFilename,
                'is_selective' => $isSelective,
                'count_selected' => is_array($selectedDnis) ? count($selectedDnis) : 'ALL'
            ]);

            $path = $file->storeAs('temp_imports', $tmpFilename);
            
            if (!$path) {
                Log::error("ImportController: storeAs returned false");
                return response()->json(['success' => false, 'message' => 'Error: No se pudo guardar el archivo en el disco.'], 500);
            }

            $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($path);
            Log::info("ImportController: File stored", ['path' => $path, 'full_path' => $fullPath]);
            
            // Verify file exists before processing
            if (!file_exists($fullPath)) {
                Log::error("ImportController: file_exists failed for path: " . $fullPath);
                return response()->json([
                    'success' => false, 
                    'message' => 'Error: El archivo no se encuentra en la ruta esperada: ' . $fullPath
                ], 500);
            }

            // Execute service based on extension
            if (in_array(strtolower($extension), ['xlsx', 'xls'])) {
                $result = $this->importService->importFromExcel($fullPath, $request->seller_id, $selectedDnis);
            } else {
                $result = $this->importService->importFromCsv($fullPath, $request->seller_id, $selectedDnis);
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

    public function analyze(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->can('realizar_carga_masiva') && !in_array($user->role_id, [1, 2])) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
            }

            $request->validate([
                'file' => 'required|file',
                'seller_id' => 'required|exists:sellers,id'
            ]);

            $file = $request->file('file');
            $extension = $file->getClientOriginalExtension();
            $path = $file->storeAs('temp_imports', 'analyze_' . time() . '_' . $file->getClientOriginalName());
            $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($path);

            $analysis = $this->importService->analyzeFile($fullPath, $request->seller_id, $extension);

            @unlink($fullPath);

            return response()->json([
                'success' => true,
                'analysis' => $analysis
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function downloadTemplate(Request $request)
    {
        $sellerId = $request->query('seller_id');
        return $this->importService->downloadExcelTemplate($sellerId);
    }
}
