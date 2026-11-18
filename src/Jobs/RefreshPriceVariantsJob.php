<?php

namespace ManagerCore\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use ManagerCore\Services\PricingService;

/**
 * Fetches the prices plugins asked for at one market through one provider
 * they named, and stores them apart from the market's own prices. Queued by
 * the scheduled refresh (every type subscribed there), by a plugin's first
 * subscription to types with no price yet, and by a plugin's ask for newer
 * prices between scheduled refreshes. See PricingService::refreshVariants.
 */
class RefreshPriceVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Well under the queue's 960 second retry_after. MCPraisal asks ESI once per type. */
    public int $timeout = 300;

    public int $maxExceptions = 2;

    public array $backoff = [30, 120, 300];

    /**
     * @param string        $market      Market key
     * @param string        $provider    esi, fuzzwork, janice or goonpraisal
     * @param int[]|null    $typeIds     Null for every type subscribed at the market through the provider
     * @param string        $trigger     What queued it, as PricingService's TRIGGER_ constants name it
     * @param string|null   $requestedBy The plugin that asked, for a fetch between scheduled refreshes
     */
    public function __construct(
        public string $market,
        public string $provider,
        public ?array $typeIds = null,
        public string $trigger = PricingService::TRIGGER_SCHEDULE,
        public ?string $requestedBy = null
    ) {
    }

    /**
     * One fetch per market and provider at a time: the scheduled one and a
     * plugin's ask would otherwise ask the same provider for the same items,
     * and one Janice key serves every plugin. The one that finds another
     * running is dropped, since that one is fetching the same prices.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('mc-price-variants:' . $this->market . ':' . $this->provider))->dontRelease()->expireAfter(600)];
    }

    public function handle(PricingService $pricing): void
    {
        $pricing->refreshVariants($this->market, $this->provider, $this->typeIds, $this->trigger, $this->requestedBy);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[Manager Core] RefreshPriceVariantsJob: final failure for '{$this->market}' through {$this->provider}; the prices keep their last value until the next scheduled refresh", [
            'error' => $exception->getMessage(),
        ]);
    }
}
