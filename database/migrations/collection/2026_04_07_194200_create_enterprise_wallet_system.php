<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        // 1. Update Collection Credits with Currency/Country context
        Schema::connection($this->connection)->table('collection_credits', function (Blueprint $table) {
            $table->string('currency', 10)->default('COP')->index();
            $table->string('country_code', 5)->default('CO')->index();
        });

        // 2. Main Wallets table
        Schema::connection($this->connection)->create('collection_wallets', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->index();
            $table->string('currency', 10);
            $table->string('country_code', 5);
            $table->decimal('balance', 20, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['company_id', 'currency', 'country_code']);
        });

        // 3. Transactions Ledger (The Audit Log)
        Schema::connection($this->connection)->create('collection_ledger', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->index();
            $table->bigInteger('wallet_id')->index();
            $table->string('type', 20); // credit, debit, injection
            $table->string('action_type', 50); // payment, expense, loan_issue, capital_injection
            $table->decimal('amount', 20, 2);
            $table->decimal('balance_before', 20, 2);
            $table->decimal('balance_after', 20, 2);
            $table->string('reference_type', 50)->nullable();
            $table->bigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->bigInteger('user_id')->nullable(); // Who performed the action
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('collection_ledger');
        Schema::connection($this->connection)->dropIfExists('collection_wallets');
        Schema::connection($this->connection)->table('collection_credits', function (Blueprint $table) {
            $table->dropColumn(['currency', 'country_code']);
        });
    }
};
