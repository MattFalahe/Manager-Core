<?php

namespace ManagerCore\Services\PriceProviders;

/**
 * Interface for price provider adapters
 */
interface PriceProviderInterface
{
    /**
     * Get prices for given type IDs
     *
     * @param array $typeIds Array of type IDs to fetch prices for
     * @param string $market Market to fetch prices for (jita, amarr, etc)
     * @return array Associative array of type_id => price_data
     *               where price_data contains: ['buy' => [...], 'sell' => [...]]
     */
    public function getPrices(array $typeIds, string $market): array;

    /**
     * Get the name of this price provider
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if this price provider is available/configured
     *
     * @return bool
     */
    public function isAvailable(): bool;
}
