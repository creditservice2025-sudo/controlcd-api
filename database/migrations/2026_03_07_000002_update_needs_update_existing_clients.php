<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Marcar como needs_update = true a los clientes existentes que les falta:
     * - dirección (address vacía o nula)
     * - GPS (geolocation con lat=0 o null, y gps_geolocalization null)
     * - imágenes (no tienen imágenes asociadas)
     */
    public function up(): void
    {
        // 1. Sin dirección
        DB::table('clients')
            ->where(function ($q) {
                $q->whereNull('address')->orWhere('address', '');
            })
            ->where('needs_update', false)
            ->whereNull('deleted_at')
            ->update(['needs_update' => true]);

        // 2. Sin GPS real (geolocation lat=0 o null Y gps_geolocalization null)
        DB::statement("
            UPDATE clients
            SET needs_update = 1
            WHERE needs_update = 0
              AND deleted_at IS NULL
              AND gps_geolocalization IS NULL
              AND (
                geolocation IS NULL
                OR JSON_UNQUOTE(JSON_EXTRACT(geolocation, '$.latitude')) = '0'
                OR JSON_UNQUOTE(JSON_EXTRACT(geolocation, '$.latitude')) IS NULL
              )
        ");

        // 3. Sin imágenes (la tabla images usa client_id directamente)
        DB::statement("
            UPDATE clients
            SET needs_update = 1
            WHERE needs_update = 0
              AND deleted_at IS NULL
              AND NOT EXISTS (
                SELECT 1 FROM images
                WHERE images.client_id = clients.id
                  AND images.deleted_at IS NULL
              )
        ");
    }

    public function down(): void
    {
        // No revertible de forma segura
    }
};
