<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Nómina de una semana de una empresa. */
class Payroll extends Model
{
    use SoftDeletes;

    public const DRAFT = 'draft';
    public const APPROVED = 'approved';
    public const PAID = 'paid';
    public const VOID = 'void';

    protected $fillable = [
        'company_id', 'week_start', 'week_end', 'period_type', 'status', 'notes',
        'created_by', 'approved_by', 'approved_at',
        'voided_by', 'voided_at', 'void_reason',
    ];

    protected $casts = [
        'week_start' => 'date:Y-m-d',
        'week_end' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedByUser()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
