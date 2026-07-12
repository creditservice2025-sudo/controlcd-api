<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Día no laborable (feriado) para un PAÍS.
 *
 * Alcance (híbrido):
 *   company_id = null -> NACIONAL (compartido por todas las empresas del país).
 *   company_id = X    -> propio de la empresa X.
 *
 * Tipo:
 *   recurring = true  -> se repite cada año en (month, day).
 *   recurring = false -> día exacto en `date` (Y-m-d).
 *
 * Ver App\Services\BusinessCalendar para su evaluación.
 */
class Holiday extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'country_id',
        'description',
        'recurring',
        'month',
        'day',
        'date',
        'active',
    ];

    protected $casts = [
        'recurring' => 'boolean',
        'active'    => 'boolean',
        'month'     => 'integer',
        'day'       => 'integer',
        'date'      => 'date:Y-m-d',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }
}
