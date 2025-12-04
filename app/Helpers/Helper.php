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
    public static function uploadFile($file, $folder, $imageType = 'gallery', $skipCompression = false)
    {
        try {
            // Validate file exists
            if (!$file || !$file->isValid()) {
                throw new \Exception('El archivo no es válido o está corrupto');
            }

            // Validate MIME type
            $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file->getMimeType(), $allowedMimes)) {
                throw new \Exception('Tipo de archivo no permitido. Solo se permiten imágenes (JPG, PNG, GIF, WEBP)');
            }

            // Validate file size (10MB max - increased to allow compression)
            if ($file->getSize() > 10485760) {
                throw new \Exception('El archivo excede el tamaño máximo permitido de 10MB');
            }

            $basePath = public_path('images');
            $folderPath = $folder ? "{$basePath}/{$folder}" : $basePath;

            // Create directory if it doesn't exist
            if (!file_exists($folderPath)) {
                if (!mkdir($folderPath, 0777, true)) {
                    throw new \Exception('No se pudo crear el directorio de imágenes. Verifica los permisos del servidor');
                }
            }

            // Validate directory is writable
            if (!is_writable($folderPath)) {
                throw new \Exception('El directorio de imágenes no tiene permisos de escritura. Contacta al administrador del sistema');
            }

            // Compress image if not skipped and size > 500KB
            $fileToUpload = $file;
            $wasCompressed = false;

            if (!$skipCompression && $file->getSize() > 512000) {
                try {
                    \Log::info('Attempting to compress image', [
                        'original_size' => $file->getSize(),
                        'type' => $imageType
                    ]);

                    $compressedPath = self::compressImage($file, $imageType);

                    // Create a new UploadedFile from compressed temp file
                    $compressedFile = new \Illuminate\Http\UploadedFile(
                        $compressedPath,
                        $file->getClientOriginalName(),
                        'image/jpeg',
                        null,
                        true // test mode to allow temp files
                    );

                    // Only use compressed if it's actually smaller
                    if ($compressedFile->getSize() < $file->getSize()) {
                        $fileToUpload = $compressedFile;
                        $wasCompressed = true;
                        \Log::info('Using compressed image', [
                            'original_size' => $file->getSize(),
                            'compressed_size' => $compressedFile->getSize()
                        ]);
                    } else {
                        \Log::info('Compressed image not smaller, using original');
                        @unlink($compressedPath); // Clean up temp file
                    }

                } catch (\Exception $e) {
                    \Log::warning('Image compression failed, using original: ' . $e->getMessage());
                    // Continue with original file
                }
            }

            // Generate unique filename
            $extension = $wasCompressed ? 'jpg' : $fileToUpload->getClientOriginalExtension();
            $fileName = Str::random(20) . '_' . time() . '.' . $extension;
            $fullPath = $folderPath . '/' . $fileName;

            // Check if file already exists (unlikely but possible)
            if (file_exists($fullPath)) {
                $fileName = Str::random(20) . '_' . time() . '_' . rand(1000, 9999) . '.' . $extension;
                $fullPath = $folderPath . '/' . $fileName;
            }

            // Move file
            if (!$fileToUpload->move($folderPath, $fileName)) {
                throw new \Exception('No se pudo guardar el archivo. Puede que el disco esté lleno o no haya permisos suficientes');
            }

            // Verify file was actually saved
            if (!file_exists($fullPath)) {
                throw new \Exception('El archivo no se guardó correctamente. Intenta nuevamente');
            }

            // Clean up compressed temp file if it exists
            if ($wasCompressed && isset($compressedPath) && file_exists($compressedPath)) {
                @unlink($compressedPath);
            }

            return "images/" . ($folder ? "{$folder}/" : "") . $fileName;
        } catch (\Exception $e) {
            \Log::error('Error uploading file: ' . $e->getMessage(), [
                'folder' => $folder,
                'original_name' => $file ? $file->getClientOriginalName() : 'unknown',
            ]);
            throw $e;
        }
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

    /**
     * Compress an image file based on type
     *
     * @param \Illuminate\Http\UploadedFile $file The image file to compress
     * @param string $imageType Type of image (profile, document, gallery, etc.)
     * @return string Path to compressed temporary file
     * @throws \Exception
     */
    public static function compressImage($file, $imageType = 'gallery')
    {
        try {
            // Configuration by image type
            $config = [
                'profile' => ['quality' => 80, 'maxWidth' => 800, 'maxHeight' => 800],
                'document' => ['quality' => 90, 'maxWidth' => 1200, 'maxHeight' => 1200],
                'gallery' => ['quality' => 85, 'maxWidth' => 1024, 'maxHeight' => 1024],
                'money_in_hand' => ['quality' => 85, 'maxWidth' => 1024, 'maxHeight' => 1024],
                'business' => ['quality' => 85, 'maxWidth' => 1024, 'maxHeight' => 1024],
            ];

            $settings = $config[$imageType] ?? $config['gallery'];
            $filePath = $file->getRealPath();

            // Get image info
            $imageInfo = getimagesize($filePath);
            if ($imageInfo === false) {
                throw new \Exception('No se pudo leer la información de la imagen');
            }

            list($width, $height, $type) = $imageInfo;

            // Create image resource based on type
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $source = imagecreatefromjpeg($filePath);
                    break;
                case IMAGETYPE_PNG:
                    $source = imagecreatefrompng($filePath);
                    break;
                case IMAGETYPE_GIF:
                    $source = imagecreatefromgif($filePath);
                    break;
                case IMAGETYPE_WEBP:
                    $source = imagecreatefromwebp($filePath);
                    break;
                default:
                    throw new \Exception('Tipo de imagen no soportado para compresión');
            }

            if ($source === false) {
                throw new \Exception('No se pudo crear la imagen desde el archivo');
            }

            // Calculate new dimensions if needed
            $needsResize = $width > $settings['maxWidth'] || $height > $settings['maxHeight'];

            if ($needsResize) {
                $ratio = min($settings['maxWidth'] / $width, $settings['maxHeight'] / $height);
                $newWidth = (int) ($width * $ratio);
                $newHeight = (int) ($height * $ratio);
            } else {
                $newWidth = $width;
                $newHeight = $height;
            }

            // Create new image
            $destination = imagecreatetruecolor($newWidth, $newHeight);

            // Preserve transparency for PNG and GIF
            if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
                imagealphablending($destination, false);
                imagesavealpha($destination, true);
                $transparent = imagecolorallocatealpha($destination, 255, 255, 255, 127);
                imagefilledrectangle($destination, 0, 0, $newWidth, $newHeight, $transparent);
            }

            // Resample image
            imagecopyresampled(
                $destination,
                $source,
                0,
                0,
                0,
                0,
                $newWidth,
                $newHeight,
                $width,
                $height
            );

            // Save to temporary file
            $tempPath = sys_get_temp_dir() . '/' . uniqid('img_') . '.jpg';

            // Save as JPEG with quality setting
            if (!imagejpeg($destination, $tempPath, $settings['quality'])) {
                throw new \Exception('No se pudo guardar la imagen comprimida');
            }

            // Free memory
            imagedestroy($source);
            imagedestroy($destination);

            // Verify compressed file exists and is smaller
            if (!file_exists($tempPath)) {
                throw new \Exception('El archivo comprimido no se creó correctamente');
            }

            $originalSize = filesize($filePath);
            $compressedSize = filesize($tempPath);

            \Log::info('Image compressed', [
                'type' => $imageType,
                'original_size' => $originalSize,
                'compressed_size' => $compressedSize,
                'reduction' => round((1 - $compressedSize / $originalSize) * 100, 2) . '%',
                'dimensions' => "{$width}x{$height} → {$newWidth}x{$newHeight}"
            ]);

            return $tempPath;

        } catch (\Exception $e) {
            \Log::error('Error compressing image: ' . $e->getMessage());
            throw $e;
        }
    }
}
