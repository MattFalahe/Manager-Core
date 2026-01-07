<?php

namespace ManagerCore\Services\PriceProviders;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use ManagerCore\Models\MarketPrice;

/**
 * SeAT Price Provider adapter (uses seat-prices-core system)
 */
class SeatPriceProvider implements PriceProviderInterface
{
    /**
     * Get prices for given type IDs using SeAT's price provider system
     *
     * @param array $typeIds
     * @param string $market
     * @return array
     */
    public function getPrices(array $typeIds, string $market): array
    {
        if (!$this->isAvailable()) {
            Log::warning("[Manager Core] SeAT Price Provider is not available");
            return [];
        }

        Log::info("[Manager Core] Fetching prices from SeAT Price Provider for " . count($typeIds) . " types in {$market}");

        try {
            // Get the configured price provider from SeAT
            $priceProvider = $this->getPriceProviderInstance();

            if (!$priceProvider) {
                Log::error("[Manager Core] Failed to get price provider instance");
                return [];
            }

            // Create collection of priceable items
            $items = collect($typeIds)->map(function ($typeId) {
                return new \ManagerCore\Services\PriceProviders\PriceableItem($typeId, 1);
            });

            // Get configuration from settings
            $configuration = $this->getPriceProviderConfiguration($market);

            // Fetch prices
            $priceProvider->getPrices($items, $configuration);

            // Store prices in our database
            $this->storePrices($items, $market);

            return [];

        } catch (\Exception $e) {
            Log::error("[Manager Core] Error fetching prices from SeAT provider: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Get the configured price provider instance
     *
     * @return mixed|null
     */
    protected function getPriceProviderInstance()
    {
        // Check if prices-core package is installed
        if (!class_exists('RecursiveTree\Seat\PricesCore\Utils\PriceProviderHelper')) {
            return null;
        }

        try {
            // Get the default price provider backend
            $helper = app('RecursiveTree\Seat\PricesCore\Utils\PriceProviderHelper');
            $providerName = config('prices-core.default');

            if (!$providerName) {
                Log::warning("[Manager Core] No default price provider configured in SeAT");
                return null;
            }

            return $helper->getProvider($providerName);

        } catch (\Exception $e) {
            Log::error("[Manager Core] Failed to get price provider: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get price provider configuration
     *
     * @param string $market
     * @return array
     */
    protected function getPriceProviderConfiguration(string $market): array
    {
        $providerName = config('prices-core.default');

        if (!$providerName) {
            return [];
        }

        $config = config("prices-core.providers.{$providerName}", []);

        // Override market if needed
        if (isset($config['market'])) {
            $config['market'] = $market;
        }

        return $config;
    }

    /**
     * Store prices from SeAT provider into our database
     *
     * @param Collection $items
     * @param string $market
     * @return void
     */
    protected function storePrices(Collection $items, string $market)
    {
        foreach ($items as $item) {
            $price = $item->getPrice();
            $typeId = $item->getTypeID();

            if ($price === null || $price <= 0) {
                continue;
            }

            // Store as both buy and sell price (SeAT providers typically return a single price)
            // You can adjust this logic based on provider configuration
            $isBuy = config('prices-core.providers.' . config('prices-core.default') . '.is_buy', false);

            $priceType = $isBuy ? 'buy' : 'sell';

            MarketPrice::updateOrCreate(
                [
                    'type_id' => $typeId,
                    'market' => $market,
                    'price_type' => $priceType,
                ],
                [
                    'price_min' => $price,
                    'price_max' => $price,
                    'price_avg' => $price,
                    'price_median' => $price,
                    'price_percentile' => $price,
                    'price_stddev' => 0,
                    'volume' => 0,
                    'order_count' => 0,
                    'strategy' => 'seat-price-provider',
                    'updated_at' => now(),
                ]
            );
        }

        Log::info("[Manager Core] Stored prices for " . $items->count() . " items from SeAT provider");
    }

    /**
     * Get the name of this price provider
     *
     * @return string
     */
    public function getName(): string
    {
        $providerName = config('prices-core.default', 'Unknown');
        return "SeAT Price Provider ({$providerName})";
    }

    /**
     * Check if SeAT price provider is available
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        // Check if prices-core is installed
        if (!class_exists('RecursiveTree\Seat\PricesCore\Utils\PriceProviderHelper')) {
            return false;
        }

        // Check if a provider is configured
        $provider = config('prices-core.default');

        return $provider !== null;
    }
}
