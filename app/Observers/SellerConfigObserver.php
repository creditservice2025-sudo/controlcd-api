<?php

namespace App\Observers;

use App\Models\SellerConfig;
use App\Models\SellerConfigAudit;
use Illuminate\Support\Facades\Auth;

class SellerConfigObserver
{
    /**
     * Disparado al crear el primer registro de config para un seller.
     * Guarda todos los valores iniciales como "old=null, new=value".
     */
    public function created(SellerConfig $config): void
    {
        $changes = [];
        foreach ($config->getAttributes() as $key => $value) {
            if (in_array($key, ['id', 'created_at', 'updated_at'])) continue;
            $changes[$key] = ['old' => null, 'new' => $value];
        }
        $this->record($config, 'created', $changes);
    }

    /**
     * Disparado en cada save() cuando cambia al menos un atributo.
     * Solo persiste el diff (campos modificados), no la fila entera.
     */
    public function updated(SellerConfig $config): void
    {
        $dirty = $config->getChanges(); // campos que cambiaron en este save
        if (empty($dirty)) return;

        $original = $config->getOriginal();
        $changes = [];
        foreach ($dirty as $key => $newValue) {
            if (in_array($key, ['updated_at'])) continue;
            $changes[$key] = [
                'old' => $original[$key] ?? null,
                'new' => $newValue,
            ];
        }
        if (empty($changes)) return;

        $this->record($config, 'updated', $changes);
    }

    private function record(SellerConfig $config, string $event, array $changes): void
    {
        $user = Auth::user();
        SellerConfigAudit::create([
            'seller_config_id' => $config->id,
            'seller_id' => $config->seller_id,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'event' => $event,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }
}
