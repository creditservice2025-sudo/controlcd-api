<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ImageApprovalRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'entity_type',
        'entity_id',
        'image_type',
        'new_image_path',
        'status',
        'token',
        'expires_at',
        'reason'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Relación con el usuario que realiza la solicitud (Vendedor).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación polimórfica manual para la entidad (Seller o Client).
     */
    public function entity()
    {
        return $this->morphTo(null, 'entity_type', 'entity_id');
    }

    /**
     * Generar un token de 6 dígitos único.
     */
    public function generateToken()
    {
        $this->token = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->expires_at = now()->addHours(24);
        $this->save();
        
        return $this->token;
    }

    /**
     * Scope para solicitudes pendientes.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
