<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SanitizeFileUploads
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Solo procesar si hay archivos en la petición
        if ($request->files->count() > 0) {
            $this->sanitizeFileBag($request->files);
        }

        return $next($request);
    }

    /**
     * Sanitize a FileBag recursively.
     *
     * @param \Symfony\Component\HttpFoundation\FileBag $fileBag
     */
    private function sanitizeFileBag($fileBag)
    {
        $basePath = base_path();
        $publicPath = public_path();

        foreach ($fileBag->keys() as $key) {
            $file = $fileBag->get($key);

            if (is_array($file)) {
                $this->sanitizeArray($file, $fileBag, $key, $basePath, $publicPath);
            } else {
                if ($this->isInvalidFile($file, $basePath, $publicPath)) {
                    $fileBag->remove($key);
                    Log::warning("SanitizeFileUploads: Removed invalid file from request key: {$key}");
                }
            }
        }
    }

    /**
     * Helper to sanitize nested files in arrays.
     */
    private function sanitizeArray(&$files, $parentBag, $parentKey, $basePath, $publicPath)
    {
        foreach ($files as $key => &$value) {
            if (is_array($value)) {
                $this->sanitizeArray($value, null, null, $basePath, $publicPath);
            } elseif ($value instanceof UploadedFile) {
                if ($this->isInvalidFile($value, $basePath, $publicPath)) {
                    unset($files[$key]);
                    Log::warning("SanitizeFileUploads: Removed invalid nested file from request key: {$parentKey}.{$key}");
                }
            }
        }
        
        // Update the bag if we modified the top-level array
        if ($parentBag) {
            $parentBag->set($parentKey, $files);
        }
    }

    /**
     * Check if a file is invalid (e.g., it's actually a directory).
     *
     * @param mixed $file
     * @param string $basePath
     * @param string $publicPath
     * @return bool
     */
    private function isInvalidFile($file, $basePath, $publicPath)
    {
        if (!$file instanceof UploadedFile) {
            return false;
        }

        try {
            $realPath = $file->getRealPath();

            // En Windows, si el archivo temporal no se creó o es inválido,
            // PSR-17 puede intentar abrir el directorio actual o el público.
            if (!$realPath || !file_exists($realPath) || is_dir($realPath) || $realPath === $basePath || $realPath === $publicPath) {
                return true;
            }
        } catch (\Exception $e) {
            return true;
        }

        return false;
    }
}
