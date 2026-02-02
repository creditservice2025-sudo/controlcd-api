<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MetricsCacheService
{
    private const CACHE_PREFIX = 'liquidation_metrics:';
    private const DEFAULT_TTL = 3600; // 1 hour

    /**
     * Get cached metrics for a specific seller and date.
     */
    public function getLiquidationMetrics(int $sellerId, string $date): ?array
    {
        $key = $this->generateKey($sellerId, $date);
        return Cache::get($key);
    }

    /**
     * Set metrics in cache.
     */
    public function setLiquidationMetrics(int $sellerId, string $date, array $metrics): void
    {
        $key = $this->generateKey($sellerId, $date);
        Cache::put($key, $metrics, self::DEFAULT_TTL);
        
        Log::info("Metrics cached for seller {$sellerId} on {$date}");
    }

    /**
     * Invalidate cache for a specific seller and date.
     */
    public function invalidateLiquidationMetrics(int $sellerId, string $date): void
    {
        $key = $this->generateKey($sellerId, $date);
        Cache::forget($key);
        
        Log::info("Metrics invalidated for seller {$sellerId} on {$date}");
    }

    /**
     * Invalidate all metrics for a seller (e.g. if their config change).
     * Note: This requires a cache driver that supports tags if we want to be efficient, 
     * but for now we'll do literal date invalidations or rely on TTL.
     */
    public function invalidateAllForSeller(int $sellerId): void
    {
        // If using redis tags: Cache::tags(['seller_' . $sellerId])->flush();
        // For simple drivers, we just let them expire or invalidate specific dates.
    }

    /**
     * Generate a unique cache key.
     */
    private function generateKey(int $sellerId, string $date): string
    {
        return self::CACHE_PREFIX . "{$sellerId}_{$date}";
    }
}
