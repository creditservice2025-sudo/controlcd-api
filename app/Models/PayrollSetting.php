<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Parámetros de nómina por empresa. */
class PayrollSetting extends Model
{
    protected $fillable = ['company_id', 'period_type', 'week_start_day', 'auto_pay_at_close', 'updated_by'];

    protected $casts = ['week_start_day' => 'integer', 'auto_pay_at_close' => 'boolean'];
}
