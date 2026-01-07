<?php

namespace ManagerCore\Services\PriceProviders;

use ManagerCore\Services\ESI\MarketDataService;
use Illuminate\Support\Facades\Log;

/**
 * ESI-based price provider (fetches live market data)
 */
class ESIPriceProvider implements PriceProviderInterface
{
    /**
     * @var MarketDataService
     */
    protected $marketDataService;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->marketDataService = new MarketDataService();
    }

    /**
     * Get prices for given type IDs
     *
     * @param array $typeIds
     * @param string $market
     * @return array
     */
    public function getPrices(array $typeIds, string $market): array
    {
        Log::info("[Manager Core] Fetching prices from ESI for " . count($typeIds) . " types in {$market}");

        // Use the existing MarketDataService to fetch and calculate prices
        $this->marketDataService->updateMarketPrices($typeIds, $market);

        // Prices are now in the database, so we return empty
        // (the PricingService will fetch from DB)
        return [];
    }

    /**
     * Get the name of this price provider
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ESI (Live Market Data)';
    }

    /**
     * Check if this price provider is available
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return true; // ESI is always available
    }
}
