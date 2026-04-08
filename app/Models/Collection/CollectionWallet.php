<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionWallet extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_wallets';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'company_id',
        'currency',
        'country_code',
        'balance',
    ];

    public function ledger()
    {
        return $this->hasMany(CollectionLedger::class, 'wallet_id', 'id');
    }
}
