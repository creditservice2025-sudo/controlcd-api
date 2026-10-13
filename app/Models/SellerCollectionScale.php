<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Meta de recaudo de una empresa para una moneda: la escala de colores del
 * Resumen General de vendedores. Ver la migración
 * 2026_10_12_000000_create_seller_collection_scales_tables y
 * App\Services\CollectionScaleService.
 */
class SellerCollectionScale extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'currency', 'seller_id', 'period_type',
        'cut_red', 'cut_yellow', 'cut_blue', 'colors',
        'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        // Color de cada tramo; null = paleta de siempre. Ver la migración
        // 2026_10_12_000002_add_colors_to_seller_collection_scales.
        'colors' => 'array',
        'cut_red' => 'float',
        'cut_yellow' => 'float',
        'cut_blue' => 'float',
    ];

    /** Vendedor de una meta propia; null en la meta general de la moneda. */
    public function seller()
    {
        return $this->belongsTo(Seller::class)->withTrashed();
    }

    // withTrashed: el nombre del autor sigue apareciendo aunque el usuario se
    // haya dado de baja.
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
