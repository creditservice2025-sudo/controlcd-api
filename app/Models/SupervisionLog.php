<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un tramo de supervisión de un Supervisor (rol 6) sobre una ruta (seller).
 * ended_at NULL = supervisión en curso. Ver App\Services\SupervisorLockService
 * para su apertura/cierre automático.
 */
class SupervisionLog extends Model
{
    protected $fillable = [
        'supervisor_user_id',
        'seller_id',
        'company_id',
        'started_at',
        'ended_at',
        'end_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function seller()
    {
        return $this->belongsTo(Seller::class, 'seller_id');
    }
}
