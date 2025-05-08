<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Country extends Model
{

    protected $fillable = ['name', 'status'];

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

}
