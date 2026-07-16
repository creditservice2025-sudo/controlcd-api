<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

/**
 * Vínculo Telegram ↔ usuario/empresa de Collection + estado de la conversación
 * guiada para crear movimientos por el bot.
 */
class CollectionTelegramLink extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_telegram_links';

    protected $fillable = [
        'chat_id',
        'user_id',
        'company_id',
        'phone',
        'tg_username',
        'tg_first_name',
        'currency',
        'country_code',
        'state',
        'draft',
        'verify_code',
        'verify_expires_at',
        'linked_at',
    ];

    protected $casts = [
        'draft' => 'array',
        'verify_expires_at' => 'datetime',
        'linked_at' => 'datetime',
    ];

    /**
     * Vinculado solo cuando la verificación se completó (linked_at != null).
     * Durante 'awaiting_code' hay user_id/company_id "pendientes" pero linked_at
     * sigue nulo, así que no se considera vinculado.
     */
    public function isLinked(): bool
    {
        return !empty($this->user_id) && !empty($this->company_id) && !empty($this->linked_at);
    }
}
