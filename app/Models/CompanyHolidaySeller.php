<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyHolidaySeller extends Model
{
    use HasFactory;

    protected $table = 'company_holiday_seller';
    
    protected $fillable = [
        'company_id',
        'holiday_id',
        'seller_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'holiday_id' => 'integer',
        'seller_id' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function holiday()
    {
        return $this->belongsTo(Holiday::class);
    }

    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }
}
