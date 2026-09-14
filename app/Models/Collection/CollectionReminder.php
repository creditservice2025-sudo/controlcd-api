<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionReminder extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_reminders';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'installment_id',
        'credit_id',
        'client_id',
        'channel',
        'sent_by_user_id',
        'sent_at',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
