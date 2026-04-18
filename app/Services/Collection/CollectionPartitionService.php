<?php

namespace App\Services\Collection;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CollectionPartitionService
{
    private const CONNECTION = 'collection_pgsql';

    /**
     * List of tables that are partitioned by company_id in the collection schema.
     */
    private const PARTITIONED_TABLES = [
        'collection_clients',
        'collection_client_audits',
        'collection_credits',
        'collection_installments',
        'collection_payments',
        'collection_expenses',
        'collection_wallets',
        'collection_ledger',
    ];

    /**
     * Ensure all required partitions exist for a given company.
     * This is safe to call multiple times as it uses "IF NOT EXISTS".
     */
    public function ensurePartitions(int $companyId): void
    {
        if ($companyId <= 0) {
            return;
        }

        try {
            foreach (self::PARTITIONED_TABLES as $table) {
                $partitionTable = sprintf('%s_company_%d', $table, $companyId);
                
                // Using raw statement for PostgreSQL declarative partitioning
                DB::connection(self::CONNECTION)->statement(
                    "CREATE TABLE IF NOT EXISTS {$partitionTable} PARTITION OF {$table} FOR VALUES IN ({$companyId})"
                );
            }
        } catch (\Exception $e) {
            Log::error("Failed to ensure collection partitions for company {$companyId}: " . $e->getMessage());
            // We don't throw here to avoid blocking the main flow if the partition exists 
            // but the check failed for some reason, though SQLSTATE 23514 will likely occur later if it actually failed.
        }
    }
}
