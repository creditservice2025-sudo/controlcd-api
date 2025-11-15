<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class Helper
{
    /**
     * Sube un archivo al storage de Laravel y retorna la ruta.
     *
     * @param \Illuminate\Http\UploadedFile $file El archivo a subir.
     * @param string $folder La carpeta donde se almacenará el archivo.
     * @return string La ruta del archivo en el storage.
     */
    public static function uploadFile($file, $folder)
    {
        $basePath = public_path('images');
        
        $folderPath = $folder ? "{$basePath}/{$folder}" : $basePath;
        if (!file_exists($folderPath)) {
            mkdir($folderPath, 0777, true);
        }
    
        $fileName = Str::random(20) . '_' . time() . '.' . $file->getClientOriginalExtension();
    
        $file->move($folderPath, $fileName);
    
        return "images/" . ($folder ? "{$folder}/" : "") . $fileName;
    }

    /**
     * Elimina un archivo del storage de Laravel.
     *
     * @param string $filePath La ruta del archivo a eliminar.
     * @return bool True si el archivo fue eliminado, false si no existe.
     */
    public static function deleteFile($filePath)
    {
        $fullPath = public_path($filePath);
        
        if (file_exists($fullPath)) {
            unlink($fullPath);
            return true;
        }
    
        return false;
    }
}
