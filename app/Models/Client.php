<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\Guarantor as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Client extends Model
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'status',
        'dni',
        'address',
        'gps_address',
        'gps_geolocalization',
        'geolocation',
        'phone',
        'email',
        'company_name',
        'guarantor_id',
        'seller_id',
        'routing_order',
        'capacity',
    ];

    protected $casts = [
        'geolocation' => 'array',
        'gps_geolocalization' => 'array',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });

        static::saving(function ($client) {
            if ($client->isDirty('routing_order')) {
                $newOrder = $client->routing_order;
                $sellerId = $client->seller_id;
                $originalOrder = $client->getOriginal('routing_order');
                $clientId = $client->id;

                $conflictingClient = self::where('seller_id', $sellerId)
                    ->where('routing_order', $newOrder)
                    ->first();

                if ($conflictingClient) {
                    DB::transaction(function () use ($sellerId, $newOrder, $originalOrder, $clientId) {
                        if ($originalOrder === null || $newOrder < $originalOrder) {
                            self::where('seller_id', $sellerId)
                                ->where('routing_order', '>=', $newOrder)
                                ->where('id', '!=', $clientId)
                                ->increment('routing_order');
                        } else {
                            self::where('seller_id', $sellerId)
                                ->where('routing_order', '>', $originalOrder)
                                ->where('routing_order', '<=', $newOrder)
                                ->where('id', '!=', $clientId)
                                ->decrement('routing_order');
                        }
                    });
                }
            }
        });

        static::deleting(function ($client) {
            $sellerId = $client->seller_id;
            $order = $client->routing_order;

            DB::transaction(function () use ($sellerId, $order) {
                self::where('seller_id', $sellerId)
                    ->where('routing_order', '>', $order)
                    ->decrement('routing_order');
            });
        });
    }


    public function guarantors()
    {
        return $this->belongsToMany(Guarantor::class, 'credits', 'client_id', 'guarantor_id');
    }

    public function guarantor(): BelongsTo
    {
        return $this->belongsTo(Guarantor::class);
    }

    public function history()
    {
        return $this->hasMany(ClientHistory::class)->orderBy('created_at', 'desc');
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

    public function payments()
    {
        return $this->hasManyThrough(
            Payment::class,
            Credit::class,
            'client_id',
            'credit_id',
            'id',
            'id'
        );
    }
    public function getCoordinatesAttribute()
    {
        return $this->geolocation;
    }
}
