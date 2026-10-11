<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Regla de comisión. seller_id NULL = regla general de la empresa para esa
 * moneda; con seller_id = excepción de ese vendedor.
 */
class PayrollRule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'currency', 'seller_id', 'active', 'period_type', 'week_start_day',
        'collection_mode', 'collection_percentage', 'collection_tiers',
        'placement_mode', 'placement_percentage',
        'fixed_salary', 'allowance', 'fixed_deductions',
        'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'active' => 'boolean',
        'week_start_day' => 'integer',
        'collection_percentage' => 'float',
        'collection_tiers' => 'array',
        'placement_percentage' => 'float',
        'fixed_salary' => 'float',
        'allowance' => 'float',
        'fixed_deductions' => 'array',
    ];

    public function seller()
    {
        return $this->belongsTo(Seller::class)->withTrashed();
    }

    // Trazabilidad: quién creó, editó y eliminó la regla. withTrashed para que
    // el nombre siga apareciendo aunque el usuario se haya dado de baja.
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    public function remover()
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }
}
