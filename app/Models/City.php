<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class City extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = ['name', 'country_id', 'status'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function client()
    {
        return $this->hasMany(Client::class);
    }
    public function sellers()
    {
        return $this->hasMany(Seller::class);
    }
}
