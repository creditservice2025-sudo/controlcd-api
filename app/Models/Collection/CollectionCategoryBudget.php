<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

/**
 * Presupuesto mensual por categoría de gasto (Collection / Deuda & Abono).
 * Un registro por (company_id, category).
 */
class CollectionCategoryBudget extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_category_budgets';

    protected $fillable = [
        'company_id',
        'category',
        'amount',
        'currency',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];
}
