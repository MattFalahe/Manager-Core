<?php

namespace ManagerCore\Services\Watchdog\Checks;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ManagerCore\Services\EcosystemVersionChecker;
use ManagerCore\Services\Watchdog\WatchdogCheck;

/**
 * Alert when any plugin in the suite has a new release on Packagist that
 * the local install hasn't picked up yet.
 *
 * Reuses EcosystemVersionChecker (the same service that powers the Plugin
 * Bridge page's version badges — 6h Packagist cache, Composer
 * InstalledVersions for the local resolve). Only plugins reporting
 * status='outdated' are included. Dev branches, Coming Soon, and
 * Packagist-unreachable plugins are silently skipped.
 *
 * Per-version dedup with a 7-day cooldown:
 *   Cache key: mc:watchdog:plugin_update:{plugin_key}:{latest_version}
 *
 * The dedup MUST be per-(plugin, version) rather than the default
 * per-check key Watchdog uses — same plugin upgrading to a new version
 * is a fresh event, not a duplicate of the previous one.
 *
 * Behavior:
 *   Day 0  : MC sees MM 2.0.0 → 2.0.1 available. Fires alert,
 *            sets key :mining-manager:2.0.1 with 7-day TTL.
 *   Day 1-6: Same check runs every 5 minutes, dedup key alive → silent.
 *   Day 7  : Key expired. If still outdated, fires reminder.
 *   Operator upgrades MM: status flips to 'current', check returns null.
 *
 * When 2.0.2 ships in 3 days, the dedup key is :mining-manager:2.0.2
 * (different version → different key) so the operator hears about the
 * new release immediately rather than waiting on the 7-day timer
 * for 2.0.1.
 *
 * Batched alert format: ONE message lists every fresh outdated plugin
 * (not one alert per plugin). Easier to scan in a busy channel,
 * encourages doing the upgrades together. Per-plugin dedup keys still
 * apply — if MM was already pinged yesterday, today's batch shows only
 * the other plugins.
 */
class PluginUpdatesAvailableCheck implements WatchdogCheck
{
    /** 7 days in seconds. Same plugin/version won't re-ping within this window. */
    private const DEDUP_TTL_SECONDS = 7 * 24 * 3600;

    public function name(): string { return 'plugin_updates_available'; }
    public function label(): string { return 'Plugin updates available'; }
    public function description(): string
    {
        return 'Alerts when any plugin in the suite has a new tagged release on Packagist that this install hasn\'t picked up yet. 7-day per-(plugin, version) cooldown so the same release won\'t spam — but a NEW version cuts the cooldown immediately. Reuses the same data the Plugin Bridge page shows.';
    }

    public function run(): ?array
    {
        try {
            $statusMap = app(EcosystemVersionChecker::class)->getStatusForAllPlugins();

            $freshOutdated = [];
            $dedupKeys = [];

            foreach ($statusMap as $key => $info) {
                if (($info['status'] ?? null) !== 'outdated') {
                    continue;
                }
                $latest = $info['latest'] ?? null;
                if ($latest === null || $latest === '') {
                    continue; // defensive — shouldn't happen for 'outdated' but skip just in case
                }

                $dedupKey = 'mc:watchdog:plugin_update:' . $key . ':' . $latest;
                if (Cache::has($dedupKey)) {
                    continue; // already pinged about THIS version within the 7-day cooldown
                }

                $freshOutdated[] = [
                    'plugin_key' => $key,
                    'name'       => $this->prettyName($key, $info['package'] ?? null),
                    'current'    => $info['current'] ?? '?',
                    'latest'     => $latest,
                    'release_url'=> $info['release_url'] ?? null,
                ];
                $dedupKeys[] = ['key' => $dedupKey, 'ttl' => self::DEDUP_TTL_SECONDS];
            }

            if (empty($freshOutdated)) {
                return null;
            }

            $count = count($freshOutdated);
            $bullets = array_map(function ($p) {
                return '• ' . $p['name'] . '  ' . $p['current'] . ' → ' . $p['latest'];
            }, $freshOutdated);

            $message = "{$count} plugin update" . ($count === 1 ? '' : 's')
                . " available on Packagist:\n" . implode("\n", $bullets)
                . "\n\nSee MC → Plugin Bridge for the full version status.";

            return [
                'title'      => 'Plugin updates available',
                'message'    => $message,
                'severity'   => 'warning',
                'context'    => [
                    'update_count' => $count,
                    'plugins'      => implode(', ', array_map(fn($p) => $p['plugin_key'], $freshOutdated)),
                ],
                // dedup_keys: tells WatchdogService to use these per-item
                // keys instead of the default per-check key. Service sets
                // all of them AFTER successful delivery; if delivery fails
                // none are set, so the operator gets pinged next tick.
                'dedup_keys' => $dedupKeys,
                // embed_url: makes the Discord embed title / Slack
                // title_link clickable straight to the Plugin Bridge page.
                'embed_url'  => $this->buildBridgeUrl(),
            ];
        } catch (\Throwable $e) {
            Log::warning('[MC Watchdog] PluginUpdatesAvailableCheck error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve a human-readable plugin name. EcosystemVersionChecker
     * returns the Composer package slug (e.g. 'mattfalahe/mining-manager'),
     * but the compatible_plugins config has nicer display names.
     */
    protected function prettyName(string $key, ?string $package): string
    {
        try {
            $config = config('manager-core.bridge.compatible_plugins.' . $key);
            if (is_array($config) && !empty($config['name'])) {
                return (string) $config['name'];
            }
        } catch (\Throwable $e) {
            // fall through
        }

        // MC isn't in compatible_plugins (it IS the hub), so special-case it.
        if ($key === 'manager-core') {
            return 'Manager Core';
        }

        return $package ?? $key;
    }

    /**
     * Resolve the Plugin Bridge URL via Laravel's route helper. Works
     * from CLI (Watchdog cron context) as long as APP_URL is set in .env,
     * which SeAT requires anyway. Returns null on any resolution error
     * so the alert still goes out, just without a clickable title.
     */
    protected function buildBridgeUrl(): ?string
    {
        try {
            return route('manager-core.bridge.index');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
