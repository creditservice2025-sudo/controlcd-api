<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class Company extends Model
{

    use HasFactory, Notifiable, HasRoles, HasApiTokens, SoftDeletes;
    protected $fillable = [
        'user_id',
        'code',
        'ruc',
        'name',
        'phone',
        'email',
        'logo_path'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}