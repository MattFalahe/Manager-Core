<?php

namespace ManagerCore\Services\Watchdog\Checks;

use Illuminate\Support\Facades\Log;
use ManagerCore\Models\Market;
use ManagerCore\Services\Watchdog\WatchdogCheck;

/**
 * Alert when any price provider configured on an ENABLED market reports
 * `isAvailable() === false`.
 *
 * Failure modes this catches:
 *   - Operator set a market's provider to 'janice' without setting the
 *     Janice API key
 *   - Operator set a market's provider to 'seat' but no seat-prices-core
 *     sub-provider is installed
 *   - One of the third-party providers (Goonpraisal, Janice) silently
 *     dropped its `isAvailable()` to false after a config change
 *
 * The check walks the markets table, resolves each unique provider in
 * use on enabled rows, and instantiates the provider class to call
 * isAvailable(). Cheap — no upstream HTTP, just credential checks.
 */
class ProviderUnavailableCheck implements WatchdogCheck
{
    public function name(): string { return 'provider_unavailable'; }
    public function label(): string { return 'Price provider unavailable'; }
    public function description(): string
    {
        return 'Alerts when any price provider routing an enabled market reports isAvailable=false. Typically means a credential is missing (Janice API key, SeAT prices-core sub-provider) for a provider you\'ve configured.';
    }

    public function run(): ?array
    {
        try {
            // Distinct provider keys used by enabled markets only —
            // disabled markets aren't actively read from, so a broken
            // provider config on a disabled row isn't an alert worth
            // firing.
            $inUseProviders = Market::where('is_enabled', true)
                ->whereNotNull('provider')
                ->distinct()
                ->pluck('provider')
                ->all();

            if (empty($inUseProviders)) {
                return null;
            }

            $unavailable = [];
            foreach ($inUseProviders as $providerKey) {
                $instance = $this->instantiateProvider((string) $providerKey);
                if ($instance === null) continue; // unknown provider key — Master Test catches this separately

                try {
                    if (!$instance->isAvailable()) {
                        $unavailable[] = $providerKey;
                    }
                } catch (\Throwable $e) {
                    // Provider's isAvailable threw — treat as unavailable
                    $unavailable[] = $providerKey . ' (isAvailable threw: ' . $e->getMessage() . ')';
                }
            }

            if (empty($unavailable)) {
                return null;
            }

            return [
                'title' => 'Price provider unavailable',
                'message' => "Configured but unavailable on enabled markets: " . implode(', ', $unavailable) . ". Check credentials at MC → Settings (Janice key, Goonpraisal email, SeAT sub-provider chain). See Diagnostics → Price Providers for live test buttons.",
                'severity' => 'warning',
                'context' => [
                    'unavailable_providers' => implode(',', $unavailable),
                    'providers_in_use' => implode(',', $inUseProviders),
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('[MC Watchdog] ProviderUnavailableCheck error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Map provider key to concrete class. Mirrors
     * PricingService::getPriceProvider's switch arms but kept local so
     * this check has no dependency on PricingService internals.
     */
    protected function instantiateProvider(string $key): ?object
    {
        try {
            return match ($key) {
                'esi'         => new \ManagerCore\Services\PriceProviders\ESIPriceProvider(),
                'janice'      => new \ManagerCore\Services\PriceProviders\JanicePriceProvider(),
                'fuzzwork'    => new \ManagerCore\Services\PriceProviders\FuzzworkPriceProvider(),
                'goonpraisal' => new \ManagerCore\Services\PriceProviders\GoonpraisalPriceProvider(),
                'seat'        => new \ManagerCore\Services\PriceProviders\SeatPriceProvider(),
                default       => null,
            };
        } catch (\Throwable $e) {
            return null;
        }
    }
}
