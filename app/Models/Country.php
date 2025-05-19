<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Country extends Model
{

    protected $fillable = ['name', 'currency', 'status'];

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    public function cities()
    {
        return $this->hasMany(City::class, 'country_id'); 
    }
}
