<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Holiday extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'country_id',
        'date',
        'description',
    ];

    protected $casts = [
        'country_id' => 'integer',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function companiesWorking()
    {
        return $this->belongsToMany(Company::class, 'company_holiday_seller')
                    ->withPivot('seller_id')
                    ->withTimestamps();
    }
}
