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
    protected $signature = 'manager-core:update-prices {--market=all : Market to update (or "all" for all markets)}';

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
        $markets = config('manager-core.pricing.markets', []);

        if ($market === 'all') {
            $this->info('[Manager Core] Updating prices for all markets...');

            foreach (array_keys($markets) as $marketName) {
                $this->info("Updating {$marketName}...");
                $pricingService->updatePrices($marketName);
            }
        } else {
            if (!isset($markets[$market])) {
                $this->error("Unknown market: {$market}");
                return 1;
            }

            $this->info("[Manager Core] Updating prices for {$market}...");
            $pricingService->updatePrices($market);
        }

        $this->info('[Manager Core] Price update completed');
        return 0;
    }
}
