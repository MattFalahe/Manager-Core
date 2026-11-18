<?php

namespace ManagerCore\Console\Commands;

use Illuminate\Console\Command;
use ManagerCore\Services\PricingService;

class UpdateMarketPricesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manager-core:update-prices
                            {--market=all : Market to update (or "all" for all markets)}
                            {--types= : Comma-separated list of type IDs to update (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update market prices from ESI';

    /**
     * Execute the console command.
     *
     * @param PricingService $pricingService
     * @return int
     */
    public function handle(PricingService $pricingService)
    {
        $market = $this->option('market');
        $typesOption = $this->option('types');
        $markets = \ManagerCore\Models\Market::getEffectiveMarkets();

        // Parse type IDs if provided
        $typeIds = null;
        if ($typesOption) {
            $typeIds = array_map('intval', explode(',', $typesOption));
            $this->info('[Manager Core] Filtering to specific type IDs: ' . implode(', ', $typeIds));
        }

        // Named types are someone at a terminal, not the schedule.
        $trigger = $typeIds !== null ? PricingService::TRIGGER_MANUAL : PricingService::TRIGGER_SCHEDULE;

        if ($market === 'all') {
            $this->info('[Manager Core] Updating prices for all markets...');

            foreach (array_keys($markets) as $marketName) {
                $this->info("Updating {$marketName}...");
                $pricingService->updatePrices($marketName, $typeIds, null, $trigger);
            }
        } else {
            if (!isset($markets[$market])) {
                $this->error("Unknown or disabled market: {$market}");
                return 1;
            }

            $this->info("[Manager Core] Updating prices for {$market}...");
            $pricingService->updatePrices($market, $typeIds, null, $trigger);
        }

        // Prices plugins asked for through a provider other than the
        // market's own, fetched on the worker, one job per market and
        // provider. Named types are someone checking a market's own prices.
        if ($typeIds === null) {
            $queued = $pricingService->queueVariantRefreshes($market === 'all' ? null : $market);
            if ($queued > 0) {
                $this->info("[Manager Core] Queued {$queued} fetch(es) of prices plugins asked for through another provider");
            }
        }

        $this->info('[Manager Core] Price update completed');
        return 0;
    }
}
