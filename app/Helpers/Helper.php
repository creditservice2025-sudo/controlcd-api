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
        // Generar un nombre único para el archivo
        $fileName = Str::random(20) . '_' . time() . '.' . $file->getClientOriginalExtension();

        $filePath = $file->storeAs($folder, $fileName, 'public');

        return $filePath;
    }

    /**
     * Elimina un archivo del storage de Laravel.
     *
     * @param string $filePath La ruta del archivo a eliminar.
     * @return bool True si el archivo fue eliminado, false si no existe.
     */
    public static function deleteFile($filePath)
    {
        if (Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
            return true;
        }

        return false;
    }
}
