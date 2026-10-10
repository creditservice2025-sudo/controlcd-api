<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Línea de nómina de un vendedor: foto del cálculo de la semana. */
class PayrollItem extends Model
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const NO_PAYMENT = 'no_payment';

    protected $fillable = [
        'payroll_id', 'seller_id', 'seller_user_id', 'seller_name', 'currency',
        'rule_id', 'rule_snapshot',
        'collection_base', 'placement_capital', 'placement_interest',
        'days_with_collection', 'pending_days',
        'collection_commission', 'tier_bonus', 'placement_commission',
        'fixed_salary', 'allowance', 'bonuses_total',
        'fixed_deductions_total', 'manual_deductions_total',
        'gross', 'deductions', 'net',
        'status', 'expense_id', 'paid_at', 'paid_by', 'paid_via', 'approved_at', 'approved_by', 'pay_error',
    ];

    protected $casts = [
        'rule_snapshot' => 'array',
        'pending_days' => 'array',
        'collection_base' => 'float',
        'placement_capital' => 'float',
        'placement_interest' => 'float',
        'collection_commission' => 'float',
        'tier_bonus' => 'float',
        'placement_commission' => 'float',
        'fixed_salary' => 'float',
        'allowance' => 'float',
        'bonuses_total' => 'float',
        'fixed_deductions_total' => 'float',
        'manual_deductions_total' => 'float',
        'gross' => 'float',
        'deductions' => 'float',
        'net' => 'float',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function payroll()
    {
        return $this->belongsTo(Payroll::class);
    }

    public function adjustments()
    {
        return $this->hasMany(PayrollItemAdjustment::class);
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class)->withTrashed();
    }
}
