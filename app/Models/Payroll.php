<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_id',
        'start_date',
        'end_date',
        'total_collected',
        'total_utility',
        'commission_utility',
        'commission_collection',
        'commission_credits',
        'salary',
        'allowance',
        'deductions_savings',
        'deductions_arl',
        'net_total',
        'status',
        'receipt_path',
        'payroll_frequency',
        'payroll_start_day',
        'include_sundays',
        'updated_by_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_collected' => 'decimal:2',
        'total_utility' => 'decimal:2',
        'commission_utility' => 'decimal:2',
        'commission_collection' => 'decimal:2',
        'commission_credits' => 'decimal:2',
        'salary' => 'decimal:2',
        'allowance' => 'decimal:2',
        'deductions_savings' => 'decimal:2',
        'deductions_arl' => 'decimal:2',
        'net_total' => 'decimal:2',
        'include_sundays' => 'boolean',
    ];

    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
