<?php

namespace ManagerCore\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Static facade for the Manager Core pricing service.
 *
 * Usage:
 *   use ManagerCore\Facades\Pricing;
 *   $price = Pricing::getPrice(34);
 *
 * Plugins should depend on this facade or the
 * \ManagerCore\Contracts\PricingServiceInterface contract.
 *
 * @method static array|null getPrice($typeIds, string $market = 'jita', string $priceType = 'both')
 * @method static array getPrices(array $typeIds, string $market = 'jita', string $priceType = 'both')
 * @method static array getTrend($typeId, string $market = 'jita', int $days = 7)
 * @method static void registerTypes(string $pluginName, array $typeIds, string $market = 'jita', int $priority = 1, bool $immediateRefresh = true)
 * @method static int unregisterTypes(string $pluginName, ?string $market = null)
 * @method static array getSubscribedTypes(?string $market = null)
 * @method static void updatePrices(string $market = 'jita', $typeIds = null)
 */
class Pricing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ManagerCore\Contracts\PricingServiceInterface::class;
    }
}
