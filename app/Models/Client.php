<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\Guarantor as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, Notifiable, HasRoles, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'name',
        'dni',
        'address',
        'geolocation',
        'phone',
        'email',
        'company_name',
        'guarantor_id',
        'seller_id',
    ];

    protected $casts = [
        'geolocation' => 'array',
    ];

    public function guarantors()
    {
        return $this->belongsToMany(Guarantor::class, 'credits', 'client_id', 'guarantor_id');
    }

    public function guarantor(): BelongsTo
    {
        return $this->belongsTo(Guarantor::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }


    public function images()
    {
        return $this->hasMany(Image::class);
    }

    public function credits()
    {
        return $this->hasMany(Credit::class);
    }
}
