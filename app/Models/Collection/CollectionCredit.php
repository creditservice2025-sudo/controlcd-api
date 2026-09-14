<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionCredit extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_credits';

    protected $fillable = [
        'id',
        'company_id',
        'client_id',
        'amount',
        'interest_rate',
        'total_installments',
        'payment_frequency',
        'first_installment_date',
        'status',
        'business_date',
        'metadata',
        'currency',
        'country_code',
        'route_name',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'business_date' => 'date',
        'first_installment_date' => 'date',
        'metadata' => 'array',
    ];

    /**
     * Estado de un crédito dado de baja.
     *
     * La baja es LÓGICA: la fila nunca se borra. Un crédito que existió y movió
     * caja tiene que poder explicarse después — el corte del día, los reportes y
     * la auditoría lo necesitan. Lo que cambia es que deja de aparecer en la
     * operación diaria.
     */
    public const STATUS_CANCELLED = 'anulado';

    /** Excluye los créditos dados de baja: es el filtro de la vista operativa. */
    public function scopeNotCancelled($query)
    {
        return $query->where('status', '!=', self::STATUS_CANCELLED);
    }

    /** ¿Este crédito está dado de baja? */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function client()
    {
        return $this->belongsTo(CollectionClient::class, 'client_id', 'id');
    }

    public function payments()
    {
        return $this->hasMany(CollectionPayment::class, 'credit_id', 'id');
    }
}
