<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'price',
        'billing_cycle',
        'duration_days',
        'description',
    ];

    public function companies()
    {
        return $this->hasMany(Company::class);
    }
}
