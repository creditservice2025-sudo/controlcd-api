<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Image extends Model
{

    use SoftDeletes;
    
    protected $fillable = [
        'path',
        'type',
        'client_id'
    ];
    
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
