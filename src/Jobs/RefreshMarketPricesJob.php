<?php

namespace ManagerCore\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use ManagerCore\Services\PricingService;

/**
 * Queued job that refreshes market prices for a specific market + type set.
 *
 * Used by `PricingService::registerTypes` when a plugin subscribes new types
 * and asks for an immediate refresh. Pre-fix the refresh ran synchronously
 * inside the registerTypes call, blocking the HTTP request — for plugins
 * that subscribe hundreds of type IDs (Mining Manager subscribes ~200+
 * moon ores / ice / fuel / gas), this could exceed PHP's max_execution_time
 * and certainly the user's patience when clicking Save in a settings tab.
 *
 * Now: registerTypes dispatches this job and returns immediately. The queue
 * worker picks it up within seconds (default Redis queue) and fetches the
 * prices via the same `updatePrices` path the scheduled cron uses.
 *
 * Idempotency:
 *   - Multiple dispatches for overlapping type sets just refetch the same
 *     prices; the underlying ESI / Janice / Fuzzwork / SeAT providers are read-only.
 *   - The job's $tries=3 + $maxExceptions=2 keep transient ESI failures
 *     from poisoning the queue.
 *
 * Failure mode:
 *   - On final failure, prices remain whatever was last fetched (or null
 *     for never-fetched types). MC's scheduled `manager-core:update-prices`
 *     cron will retry on its 4-hourly schedule — operators are not blocked.
 */
class RefreshMarketPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of attempts. ESI is bursty; 3 tries with backoff covers
     * most transient outages.
     */
    public int $tries = 3;

    /**
     * Per-attempt timeout. updatePrices fetches in batches and writes to
     * a single table — 5 min is generous even for very large type sets.
     */
    public int $timeout = 300;

    /**
     * Cap on total failures across retries.
     */
    public int $maxExceptions = 2;

    /**
     * Exponential-ish backoff between retries.
     */
    public array $backoff = [30, 120, 300];

    /**
     * @param string $market   Market identifier (jita, amarr, dodixie, hek, rens)
     * @param int[]  $typeIds  Type IDs to refresh — typically the new/missing
     *                         subset returned by registerTypes' diff.
     */
    public function __construct(
        public string $market,
        public array $typeIds
    ) {}

    /**
     * Run the refresh.
     */
    public function handle(PricingService $pricing): void
    {
        if (empty($this->typeIds)) {
            return;
        }

        Log::info("[Manager Core] RefreshMarketPricesJob: refreshing " . count($this->typeIds) . " types for market '{$this->market}'");

        $pricing->updatePrices($this->market, $this->typeIds);
    }

    /**
     * Final failure (after all retries exhausted) — log so an operator can
     * see why prices stayed stale. Don't re-throw; the scheduled cron will
     * recover at the next 4-hourly tick.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("[Manager Core] RefreshMarketPricesJob: final failure for market '{$this->market}' (" . count($this->typeIds) . " types) — prices will be picked up on the next manager-core:update-prices cron tick", [
            'error' => $exception->getMessage(),
            'first_type_ids' => array_slice($this->typeIds, 0, 5),
        ]);
    }
}
