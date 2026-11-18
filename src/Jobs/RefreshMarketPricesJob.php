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
 * PricingService also queues it, ten minutes on, for the types a refresh
 * failed to fetch (see $retry), for the end of a debounce window when
 * a plugin subscribed inside it (see $waiting), and when a plugin asks for
 * prices it finds too old between scheduled refreshes (see $requestedBy).
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
     * The one more try for types a refresh failed to fetch. A plain property
     * rather than a promoted one, so a job queued before it existed still
     * unserializes with its default.
     */
    public bool $retry = false;

    /**
     * The fetch queued for the end of a debounce window. It carries no type
     * IDs: plugins subscribing inside the window leave theirs waiting, and
     * it takes whichever are waiting when it runs. A plain property for the
     * same reason as $retry.
     */
    public bool $waiting = false;

    /**
     * The plugin that asked for these types to be fetched again between the
     * scheduled refreshes, or null for every other fetch. A plain property
     * for the same reason as $retry.
     */
    public ?string $requestedBy = null;

    /**
     * @param string      $market      Market identifier (jita, amarr, dodixie, hek, rens)
     * @param int[]       $typeIds     Type IDs to refresh — typically the new/missing
     *                                 subset returned by registerTypes' diff.
     * @param bool        $retry       True for the one more try after a failed fetch,
     *                                 which never queues another.
     * @param bool        $waiting     True for the fetch of the types left waiting.
     * @param string|null $requestedBy The plugin a fetch between scheduled
     *                                 refreshes is for.
     */
    public function __construct(
        public string $market,
        public array $typeIds,
        bool $retry = false,
        bool $waiting = false,
        ?string $requestedBy = null
    ) {
        $this->retry = $retry;
        $this->waiting = $waiting;
        $this->requestedBy = $requestedBy;
    }

    /**
     * Run the refresh.
     */
    public function handle(PricingService $pricing): void
    {
        if ($this->waiting) {
            Log::info("[Manager Core] RefreshMarketPricesJob: fetching the types left waiting for market '{$this->market}'");
            $pricing->refreshWaiting($this->market);
            return;
        }

        if (empty($this->typeIds)) {
            return;
        }

        Log::info("[Manager Core] RefreshMarketPricesJob: refreshing " . count($this->typeIds) . " types for market '{$this->market}'"
            . ($this->retry ? ' (one more try after a failed fetch)' : ''));

        if ($this->retry) {
            $pricing->retryPrices($this->market, $this->typeIds);
            return;
        }

        if ($this->requestedBy !== null) {
            $pricing->refreshRequested($this->market, $this->typeIds, $this->requestedBy);
            return;
        }

        $pricing->updatePrices($this->market, $this->typeIds, null, PricingService::TRIGGER_SUBSCRIPTION);
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
