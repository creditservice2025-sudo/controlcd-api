<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bono o descuento manual sobre una línea de nómina. */
class PayrollItemAdjustment extends Model
{
    public const BONUS = 'bonus';
    public const DEDUCTION = 'deduction';

    protected $fillable = ['payroll_item_id', 'type', 'concept', 'amount', 'created_by'];

    protected $casts = ['amount' => 'float'];

    public function item()
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }
}
