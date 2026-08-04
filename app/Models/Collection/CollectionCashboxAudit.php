<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

/**
 * Evento de auditoría de una caja de Collection (alta, renombrado, cambio de
 * saldo inicial, baja). `changes` guarda {old:{...}, new:{...}} más el nombre
 * de la caja al momento del evento, para que el historial siga siendo legible
 * aunque la caja ya no exista.
 */
class CollectionCashboxAudit extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_cashbox_audits';

    protected $fillable = [
        'company_id',
        'cashbox_id',
        'action',
        'user_id',
        'ip_address',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];
}
