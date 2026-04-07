<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionInstallment;

return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        // For each credit, recalibrate its installments
        CollectionCredit::all()->each(function ($credit) {
            $count = (int) $credit->total_installments;
            if ($count <= 0) return;

            $principal = (float) $credit->amount;
            $interestRate = (float) $credit->interest_rate;
            $totalInterest = ($principal * $interestRate) / 100;

            $principalPerInstallment = $principal / $count;
            $interestPerInstallment = $totalInterest / $count;

            CollectionInstallment::where('credit_id', $credit->id)
                ->where('company_id', $credit->company_id)
                ->update([
                    'principal_amount' => round($principalPerInstallment, 2),
                    'interest_amount' => round($interestPerInstallment, 2),
                ]);
        });
    }

    public function down(): void
    {
        // No logical down, we just leave them as they are
    }
};
