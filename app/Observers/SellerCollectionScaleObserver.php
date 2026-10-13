<?php

namespace App\Observers;

use App\Models\SellerCollectionScale;
use App\Models\SellerCollectionScaleAudit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Historial de las metas de recaudo (seller_collection_scale_audits).
 *
 * Se registra desde el modelo para que cualquier cambio quede en el historial,
 * venga de la pantalla o de un comando.
 */
class SellerCollectionScaleObserver
{
    /** Campos que definen la meta. Los de autoría no son "cambios" de la meta. */
    public const FIELDS = ['currency', 'seller_id', 'period_type', 'cut_red', 'cut_yellow', 'cut_blue', 'colors'];

    public function created(SellerCollectionScale $scale): void
    {
        $changes = [];
        foreach (self::FIELDS as $field) {
            $changes[$field] = ['old' => null, 'new' => $scale->getAttribute($field)];
        }
        $this->record($scale, 'created', $changes);
    }

    /**
     * Solo lo que cambió. El guardado que escribe deleted_by justo antes de
     * borrar no toca ningún campo de la meta y no deja evento.
     */
    public function updated(SellerCollectionScale $scale): void
    {
        $changes = [];
        foreach (array_keys($scale->getChanges()) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                continue;
            }
            $changes[$field] = [
                'old' => $scale->getOriginal($field),
                'new' => $scale->getAttribute($field),
            ];
        }
        if ($changes) {
            $this->record($scale, 'updated', $changes);
        }
    }

    public function deleted(SellerCollectionScale $scale): void
    {
        $this->record($scale, 'deleted', []);
    }

    private function record(SellerCollectionScale $scale, string $event, array $changes): void
    {
        // Defensivo, como SellerConfigObserver: si el historial no se puede
        // escribir, la meta se guarda igual.
        try {
            $user = Auth::user();
            SellerCollectionScaleAudit::create([
                'scale_id' => $scale->id,
                'company_id' => $scale->company_id,
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'event' => $event,
                'changes' => $changes,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SellerCollectionScaleObserver: no se pudo registrar el historial', [
                'scale_id' => $scale->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
