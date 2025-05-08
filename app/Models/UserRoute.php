<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRoute extends Model
{
    protected $fillable = ['user_id', 'route_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function route()
    {
        return $this->belongsTo(Route::class);
    }
}
