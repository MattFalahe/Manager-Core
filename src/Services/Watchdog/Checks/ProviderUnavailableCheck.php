<?php

namespace ManagerCore\Services\Watchdog\Checks;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\Market;
use ManagerCore\Models\PricingPreference;
use ManagerCore\Services\PriceProviders\JanicePriceProvider;
use ManagerCore\Services\Watchdog\WatchdogCheck;

/**
 * Alert when any price provider in use on an ENABLED market, or as a
 * plugin's provider override, reports `isAvailable() === false`, when
 * Janice turned its last request away, or when an enabled market's own
 * provider failed its last refresh outright.
 *
 * Failure modes this catches:
 *   - Operator set a market's provider (or a plugin's override) to 'janice'
 *     without setting the Janice API key
 *   - Operator set a market's provider to 'seat' but no seat-prices-core
 *     sub-provider is installed
 *   - One of the third-party providers (Goonpraisal, Janice) silently
 *     dropped its `isAvailable()` to false after a config change
 *   - Janice refused the key (401/403), asked us to slow down (429), or
 *     failed every request of its last fetch. A key can be set and still be
 *     refused, which isAvailable() cannot see, so the provider's recorded
 *     last answer is read instead.
 *   - A market's provider failed its last refresh outright, say a
 *     structure no Key Pool director can read, or Fuzzwork down. Its prices
 *     come from the fallback provider or stay cached meanwhile, which is
 *     exactly why nobody would notice without this.
 *
 * Janice is reported once when it starts failing and once when it answers
 * again; the rest repeats every hour while it lasts (see buildAlert).
 *
 * The check collects each unique provider in use and instantiates the
 * provider class to call isAvailable(). Cheap — no upstream HTTP, just
 * credential checks, Janice's recorded last answer and the markets' rows.
 */
class ProviderUnavailableCheck implements WatchdogCheck
{
    /** How long one Janice failure, or its end, stays reported. */
    private const JANICE_DEDUP_TTL = 30 * 24 * 3600;

    public function name(): string { return 'provider_unavailable'; }
    public function label(): string { return 'Price provider unavailable'; }
    public function description(): string
    {
        return 'Alerts when a price provider routing an enabled market, or set as a plugin\'s provider override, reports isAvailable=false, when Janice refused its last request or failed it outright, or when an enabled market\'s provider failed its last refresh outright. Typically a missing or refused credential (Janice API key, SeAT prices-core sub-provider), a provider that is down, or a structure no Key Pool director can read.';
    }

    public function run(): ?array
    {
        try {
            // Distinct provider keys used by enabled markets only —
            // disabled markets aren't actively read from, so a broken
            // provider config on a disabled row isn't an alert worth
            // firing.
            $marketProviders = Market::where('is_enabled', true)
                ->whereNotNull('provider')
                ->distinct()
                ->pluck('provider')
                ->all();

            // An override sends that plugin's reads to its provider whatever
            // the market routes through, and is how most installs reach
            // Janice at all.
            $overrideProviders = PricingPreference::whereNotNull('provider_override')
                ->where('provider_override', '!=', '')
                ->distinct()
                ->pluck('provider_override')
                ->all();

            $inUseProviders = array_values(array_unique(array_merge($marketProviders, $overrideProviders)));

            if (empty($inUseProviders)) {
                return null;
            }

            $unavailable = [];
            $janiceFailure = null;
            foreach ($inUseProviders as $providerKey) {
                $instance = $this->instantiateProvider((string) $providerKey);
                if ($instance === null) continue; // unknown provider key — Master Test catches this separately

                try {
                    if (!$instance->isAvailable()) {
                        $unavailable[] = $providerKey;
                    } elseif ($providerKey === 'janice' && ($failure = JanicePriceProvider::lastFailure()) !== null) {
                        $janiceFailure = $failure;
                    }
                } catch (\Throwable $e) {
                    // Provider's isAvailable threw — treat as unavailable
                    $unavailable[] = $providerKey . ' (isAvailable threw: ' . $e->getMessage() . ')';
                }
            }

            $janiceBack = $janiceFailure === null && in_array('janice', $inUseProviders, true)
                ? JanicePriceProvider::lastRecovery()
                : null;

            // The row says what failed and what stood in; see
            // PricingService::recordMarketOutcome.
            $failing = [];
            $failingMarkets = Market::where('is_enabled', true)
                ->whereNotNull('last_refresh_status')
                ->where('last_refresh_status', '!=', Market::STATUS_OK)
                ->get();
            foreach ($failingMarkets as $market) {
                $detail = trim((string) ($market->last_refresh_error ?: $market->last_refresh_status));
                if (strlen($detail) > 300) {
                    $detail = substr($detail, 0, 297) . '...';
                }
                $failing[] = "{$market->key} ({$detail})";
            }

            return $this->buildAlert($unavailable, $failing, $inUseProviders, $janiceFailure, $janiceBack);
        } catch (\Throwable $e) {
            Log::warning('[MC Watchdog] ProviderUnavailableCheck error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The alert for what was found, or null when nothing in it is due.
     *
     * A refused Janice key stays refused until someone changes it, which
     * can be days, so Janice is reported once when it starts failing and
     * once when it answers again. Everything else here still repeats every
     * hour while it lasts. The alert always lists everything that is wrong
     * now, whichever part of it made the alert due.
     *
     * @param array|null $janiceFailure JanicePriceProvider::lastFailure()
     * @param array|null $janiceBack    JanicePriceProvider::lastRecovery()
     */
    protected function buildAlert(array $unavailable, array $failing, array $inUseProviders, ?array $janiceFailure, ?array $janiceBack): ?array
    {
        $janiceKey = $janiceFailure !== null ? self::janiceKey($janiceFailure['since'] ?? null) : null;
        $others = array_merge($unavailable, $failing);
        $othersKey = 'mc:watchdog:dedup:' . $this->name();

        // Only a failure that was reported gets its end reported.
        $backKey = null;
        if ($janiceBack !== null) {
            $reported = self::janiceKey($janiceBack['from'] ?? null);
            if (Cache::has($reported) && !Cache::has($reported . ':back')) {
                $backKey = $reported . ':back';
            }
        }

        $due = ($janiceKey !== null && !Cache::has($janiceKey))
            || (!empty($others) && !Cache::has($othersKey))
            || $backKey !== null;
        if (!$due) {
            return null;
        }

        $dedupKeys = [];
        if ($janiceKey !== null) {
            $dedupKeys[] = ['key' => $janiceKey, 'ttl' => self::JANICE_DEDUP_TTL];
        }
        if (!empty($others)) {
            $dedupKeys[] = ['key' => $othersKey, 'ttl' => 3600];
        }
        if ($backKey !== null) {
            $dedupKeys[] = ['key' => $backKey, 'ttl' => self::JANICE_DEDUP_TTL];
        }

        if ($janiceFailure !== null) {
            $unavailable[] = 'janice: ' . $this->describeJaniceFailure($janiceFailure);
        }
        $backLine = $backKey !== null ? 'Janice is answering again' . $this->describeRecovery($janiceBack) . '.' : null;

        if (empty($unavailable) && empty($failing)) {
            return [
                'title' => 'Price provider answering again',
                'message' => $backLine . ' Prices it fetches are its own again instead of the fallback provider\'s.',
                'severity' => 'resolved',
                'context' => [
                    'providers_in_use' => implode(',', $inUseProviders),
                ],
                'dedup_keys' => $dedupKeys,
            ];
        }

        $found = [];
        if (!empty($unavailable)) {
            $found[] = 'Configured but unavailable: ' . implode('; ', $unavailable) . '.';
        }
        if (!empty($failing)) {
            $found[] = 'Last refresh failed: ' . implode('; ', $failing) . '.';
        }
        if ($backLine !== null) {
            $found[] = $backLine;
        }

        return [
            'title' => 'Price provider unavailable',
            'message' => implode(' ', $found) . " Prices those providers should fetch come from the fallback provider or keep their cached value meanwhile. Check credentials at MC → Settings (Janice key, Goonpraisal email, SeAT sub-provider chain) and each market's status on the Markets page. See Diagnostics → Price Providers for live test buttons.",
            'severity' => 'warning',
            'context' => [
                'unavailable_providers' => implode('; ', $unavailable) ?: 'none',
                'failing_markets' => implode('; ', $failing) ?: 'none',
                'providers_in_use' => implode(',', $inUseProviders),
            ],
            'dedup_keys' => $dedupKeys,
        ];
    }

    /**
     * One key per Janice failure, named by when it started, so a failure
     * that ends and comes back is reported again.
     */
    protected static function janiceKey(?string $since): string
    {
        return 'mc:watchdog:provider_unavailable:janice:' . md5((string) $since);
    }

    /**
     * " (failing since 2026-10-30 14:00 UTC, answering since 2026-11-02 09:10 UTC)".
     */
    protected function describeRecovery(array $back): string
    {
        $parts = [];
        foreach (['from' => 'failing since', 'at' => 'answering since'] as $field => $label) {
            if (empty($back[$field])) {
                continue;
            }
            try {
                $parts[] = $label . ' ' . Carbon::parse($back[$field])->utc()->format('Y-m-d H:i') . ' UTC';
            } catch (\Throwable $e) {
                // An unreadable timestamp only costs that part.
            }
        }

        return $parts ? ' (' . implode(', ', $parts) . ')' : '';
    }

    /**
     * "Janice refused the request with HTTP 401 (check the API key), since
     * 2026-10-30 14:00 UTC".
     */
    protected function describeJaniceFailure(array $failure): string
    {
        $what = $failure['state'] === 'refused'
            ? ($failure['error'] ?? 'Janice refused the request')
            : 'every request of its last fetch failed' . (!empty($failure['error']) ? ' (' . $failure['error'] . ')' : '');

        $since = '';
        if (!empty($failure['since'])) {
            try {
                $since = ', since ' . Carbon::parse($failure['since'])->utc()->format('Y-m-d H:i') . ' UTC';
            } catch (\Throwable $e) {
                // An unreadable timestamp only costs the "since" part.
            }
        }

        return $what . $since;
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
