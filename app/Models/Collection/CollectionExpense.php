<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CollectionExpense extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'collection_pgsql';
    protected $table = 'collection_expenses';

    protected $fillable = [
        'company_id',
        'user_id',
        'amount',
        'description',
        'category',
        'status', // pending, approved, rejected
        'recorded_at',
        'business_date',
        'latitude',
        'longitude',
        'metadata', // JSON for photos, etc.
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'recorded_at' => 'datetime',
        'business_date' => 'date:Y-m-d',
        'metadata' => 'json',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
