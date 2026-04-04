<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


use Illuminate\Database\Eloquent\Factories\HasFactory;

class Country extends Model
{
    use HasFactory;
    protected $connection = 'mysql';
    protected $fillable = ['name', 'currency', 'status', 'timezone', 'phone_code'];

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    public function cities()
    {
        return $this->hasMany(City::class, 'country_id'); 
    }
}
