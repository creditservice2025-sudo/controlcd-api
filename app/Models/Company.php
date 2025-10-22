<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;


class Company extends Model
{

    use HasFactory, Notifiable, SoftDeletes;
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

    public function sellers()
    {
        return $this->hasMany(Seller::class);
    }

    public function credits()
    {
        return $this->hasManyThrough(Credit::class, Seller::class);
    }
}
