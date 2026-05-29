<?php

namespace ManagerCore\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * Resolve installed vs latest-on-Packagist versions for every plugin
 * in MC's `compatible_plugins` config — powers the version badges on
 * the Plugin Bridge page (ecosystem map + Plugin Registry table).
 *
 * Mirrors the per-plugin VersionChecker pattern (introduced in SeAT
 * Broadcast, then ported to SM + MM): one call answers "is this plugin
 * up to date, behind, ahead, on a dev branch, or unknown?" for any
 * plugin Matt maintains. The shape this service returns is intentionally
 * identical so any future "version status pill" component can render
 * either single-plugin (per-plugin checker) or many-plugin (this
 * service) data without translation.
 *
 * Resilience:
 *   - Per-plugin Packagist lookup is cached for 6 hours (one HTTP
 *     call per plugin per 6h, not per page render)
 *   - Each HTTP request has a 3-second timeout
 *   - Every failure path returns the documented 'unknown' shape so
 *     the Plugin Bridge page can't 500 on a Packagist hiccup
 *   - Plugins whose Composer package isn't installed return 'offline'
 *     (no Packagist call attempted)
 *
 * Standalone-safe: MC works fine without this — the bridge page falls
 * back to "no version info available" if every call returns unknown.
 */
class EcosystemVersionChecker
{
    /** Cache TTL (seconds). 6 hours matches the per-plugin VersionChecker pattern. */
    private const CACHE_TTL = 6 * 60 * 60;

    /** Guzzle / Http timeout (seconds). Page render can't block longer than this. */
    private const HTTP_TIMEOUT = 3;

    /** Cache key prefix — one entry per plugin under this namespace.
     *  v2 bump (2026-05-29): cache shape changed from `?string` to
     *  `array{version, reachable, has_releases}` so we can distinguish
     *  "Packagist down" from "Packagist reachable but no stable tags yet"
     *  (powers the new 'unreleased' / Coming soon status). Stale v1
     *  cache entries simply expire on their own TTL. */
    private const CACHE_PREFIX = 'mc:ecosystem_version_v2:';

    /**
     * MC's own package — not in `compatible_plugins` config because MC
     * IS the hub, not a "compatible plugin", but the Plugin Bridge page
     * still wants to show MC's version badge on the central core node.
     * Treated as a special-case key so the view can render it alongside
     * the per-plugin badges using the same status-shape contract.
     */
    private const MANAGER_CORE_KEY = 'manager-core';
    private const MANAGER_CORE_PACKAGE = 'mattfalahe/manager-core';

    /**
     * Return a status map for EVERY plugin in compatible_plugins config
     * PLUS Manager Core itself.
     *
     * The returned map includes a special 'manager-core' entry so the
     * Plugin Bridge page can render MC's version badge on its central
     * core node. MC isn't in compatible_plugins (because MC IS the hub),
     * so we synthesize the entry from MANAGER_CORE_PACKAGE.
     *
     * @return array<string, array> [plugin_key => status_shape] where
     *         status_shape follows getStatusForPlugin's documented contract.
     */
    public function getStatusForAllPlugins(): array
    {
        $plugins = config('manager-core.bridge.compatible_plugins', []);
        $out = [];
        foreach (array_keys($plugins) as $pluginKey) {
            $out[$pluginKey] = $this->getStatusForPlugin($pluginKey);
        }
        // Synthesize MC's own status — bypass the compatible_plugins
        // lookup since MC isn't in that map.
        $out[self::MANAGER_CORE_KEY] = $this->getStatusForManagerCore();
        return $out;
    }

    /**
     * Return the version status for Manager Core itself.
     *
     * Same status shape as getStatusForPlugin so renderers stay reusable.
     * The plugin_key is hardcoded 'manager-core' (matching the convention
     * MC uses internally for its own publisher slug in publisher_prefixes
     * + Topics). Composer package is hardcoded to 'mattfalahe/manager-core'.
     */
    public function getStatusForManagerCore(): array
    {
        return $this->statusForKnownPackage(self::MANAGER_CORE_KEY, self::MANAGER_CORE_PACKAGE);
    }

    /**
     * Return the version status for one plugin.
     *
     * Shape (always complete, even on failure):
     *   [
     *     'plugin_key'     => 'mining-manager',
     *     'package'        => 'mattfalahe/mining-manager',
     *     'current'        => '2.0.0' | 'dev-dev-5.0' | null (not installed),
     *     'current_source' => 'composer' | 'config' | 'none',
     *     'is_dev_branch'  => bool,
     *     'latest'         => '2.0.1' | null (Packagist unreachable),
     *     'status'         => 'current' | 'outdated' | 'ahead' | 'dev_branch' | 'unreleased' | 'unknown' | 'offline',
     *     'message'        => 'human-readable explanation',
     *     'release_url'    => 'https://github.com/.../releases/tag/v2.0.1' | null,
     *   ]
     */
    public function getStatusForPlugin(string $pluginKey): array
    {
        $config = config('manager-core.bridge.compatible_plugins.' . $pluginKey);
        if (!is_array($config) || empty($config['package'])) {
            return $this->shape($pluginKey, null, null, 'none', false, null, 'unknown', 'Plugin not in compatible_plugins config.');
        }
        return $this->statusForKnownPackage($pluginKey, (string) $config['package']);
    }

    /**
     * Build the full status shape for a known (plugin_key, package) pair.
     * Shared between getStatusForPlugin (compatible plugins) and
     * getStatusForManagerCore (MC's own self-status). Single source of
     * truth for the resolution chain — installed-via-Composer → dev-branch?
     * → Packagist compare → status enum.
     */
    protected function statusForKnownPackage(string $pluginKey, string $package): array
    {
        // Resolve installed version via Composer's runtime metadata API.
        // Returns [version, source] where source is 'composer' or 'none'.
        // For dev-branch installs (e.g. `composer require X:dev-dev-5.0`),
        // Composer returns the literal string 'dev-dev-5.0' — looksLikeDevBranch
        // detects both 'dev-*' prefix and '*-dev' suffix patterns.
        [$current, $source] = $this->resolveInstalledVersion($package);

        // Always check Packagist — even when the plugin isn't installed
        // locally — so we can distinguish two not-installed cases:
        //   (a) plugin is on Packagist with stable tags → 'offline'
        //       (you could install it; you just haven't)
        //   (b) plugin has no Packagist release yet → 'unreleased'
        //       ("Coming soon" — there's nothing for you to install)
        // The 6h per-plugin cache makes the call free after first load.
        $info = $this->fetchLatestVersionInfo($package);
        $latest = $info['version'];
        $packagistReachable = (bool) ($info['reachable'] ?? false);
        $hasReleases = (bool) ($info['has_releases'] ?? false);

        // Not installed locally? Pick between offline and unreleased
        // based on whether Packagist actually has anything to install.
        if ($current === null) {
            if ($packagistReachable && !$hasReleases) {
                return $this->shape(
                    $pluginKey,
                    $package,
                    null,
                    'none',
                    false,
                    null,
                    'unreleased',
                    'No tagged release on Packagist yet — coming soon. Not currently installed.'
                );
            }
            return $this->shape($pluginKey, $package, null, 'none', false, $latest, 'offline', 'Plugin not installed via Composer.');
        }

        $isDevBranch = $this->looksLikeDevBranch($current);

        // 'unreleased' takes priority over dev_branch + unknown:
        // Packagist is reachable AND tells us there's no stable tag yet.
        // True regardless of how the plugin happens to be installed locally
        // (dev branch or some pre-release ref). This is the "Coming soon"
        // signal — the plugin has been claimed on Packagist but hasn't
        // shipped its first stable release yet.
        if ($packagistReachable && !$hasReleases) {
            return $this->shape(
                $pluginKey,
                $package,
                $current,
                $source,
                $isDevBranch,
                null,
                'unreleased',
                'No tagged release on Packagist yet — coming soon. Currently installed: ' . $current . '.'
            );
        }

        if ($isDevBranch) {
            return $this->shape(
                $pluginKey,
                $package,
                $current,
                $source,
                true,
                $latest,
                'dev_branch',
                'Development branch (' . $current . ') — cannot compare against tagged releases. Latest stable on Packagist: ' . ($latest ?? 'unknown') . '.'
            );
        }

        if ($latest === null) {
            return $this->shape(
                $pluginKey,
                $package,
                $current,
                $source,
                false,
                null,
                'unknown',
                'Could not reach Packagist to check the latest version. The plugin is unaffected — this is informational only.'
            );
        }

        $cmp = version_compare($current, $latest);

        if ($cmp < 0) {
            $releaseUrl = $this->buildReleaseUrl($package, $latest);
            return $this->shape(
                $pluginKey,
                $package,
                $current,
                $source,
                false,
                $latest,
                'outdated',
                "A newer release is available ({$current} → {$latest}). Update via your standard plugin upgrade path.",
                $releaseUrl
            );
        }

        if ($cmp > 0) {
            return $this->shape(
                $pluginKey,
                $package,
                $current,
                $source,
                false,
                $latest,
                'ahead',
                "Running a tagged pre-release ({$current}) newer than the latest stable Packagist tag ({$latest})."
            );
        }

        return $this->shape(
            $pluginKey,
            $package,
            $current,
            $source,
            false,
            $latest,
            'current',
            "Running the latest tagged release ({$current})."
        );
    }

    /**
     * Manual cache clear for a single plugin (or all plugins when
     * $pluginKey is null). Bridge page can wire a 'Refresh versions'
     * button to this, or operators can hit it via Tinker after a
     * plugin release.
     */
    public function clearCache(?string $pluginKey = null): void
    {
        if ($pluginKey !== null) {
            // Special-case MC since it's not in compatible_plugins config.
            if ($pluginKey === self::MANAGER_CORE_KEY) {
                Cache::forget(self::CACHE_PREFIX . self::MANAGER_CORE_PACKAGE);
                return;
            }
            $config = config('manager-core.bridge.compatible_plugins.' . $pluginKey);
            if (is_array($config) && !empty($config['package'])) {
                Cache::forget(self::CACHE_PREFIX . $config['package']);
            }
            return;
        }
        // Clear all — compatible_plugins entries + MC itself.
        foreach (config('manager-core.bridge.compatible_plugins', []) as $info) {
            if (!empty($info['package'])) {
                Cache::forget(self::CACHE_PREFIX . $info['package']);
            }
        }
        Cache::forget(self::CACHE_PREFIX . self::MANAGER_CORE_PACKAGE);
    }

    /**
     * Ask Composer's runtime metadata API what version of $package is
     * installed. Returns [version, source] where source is:
     *   - 'composer' : authoritative answer from Composer's installed.json
     *   - 'none'     : package not installed (caller treats as 'offline')
     */
    protected function resolveInstalledVersion(string $package): array
    {
        if (class_exists('\\Composer\\InstalledVersions')) {
            try {
                if (\Composer\InstalledVersions::isInstalled($package)) {
                    $version = \Composer\InstalledVersions::getPrettyVersion($package);
                    if (is_string($version) && $version !== '') {
                        return [$version, 'composer'];
                    }
                }
            } catch (\Throwable $e) {
                // fall through to 'none'
            }
        }
        return [null, 'none'];
    }

    /**
     * True if the version string looks like a Composer dev-branch ref
     * (e.g. 'dev-dev-5.0', 'dev-main', 'dev-feature/foo').
     */
    protected function looksLikeDevBranch(string $version): bool
    {
        return str_starts_with($version, 'dev-') || str_ends_with($version, '-dev');
    }

    /**
     * Fetch the latest stable version tag for $package from Packagist v2.
     * Per-plugin cached for 6 hours.
     *
     * Returns an info struct so callers can distinguish the three
     * Packagist outcomes without ambiguity:
     *
     *   ['version' => '2.0.1', 'reachable' => true,  'has_releases' => true ]
     *     — Packagist OK, package has at least one stable tag
     *
     *   ['version' => null,    'reachable' => true,  'has_releases' => false]
     *     — Packagist OK, package exists but only dev-* refs (no stable
     *       tag yet — drives the 'unreleased' / Coming soon badge)
     *
     *   ['version' => null,    'reachable' => false, 'has_releases' => false]
     *     — Packagist unreachable (HTTP error / timeout / malformed response)
     *
     * Before v2 (cache prefix bump) this returned ?string, conflating
     * the second + third cases as null. Splitting them lets the UI show
     * "Coming soon" for genuinely-unreleased plugins instead of the
     * scarier "Packagist unreachable" pill.
     */
    protected function fetchLatestVersionInfo(string $package): array
    {
        $cacheKey = self::CACHE_PREFIX . $package;

        $info = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($package) {
            try {
                $url = "https://repo.packagist.org/p2/{$package}.json";
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->connectTimeout(self::HTTP_TIMEOUT)
                    ->withHeaders([
                        'Accept'     => 'application/json',
                        'User-Agent' => 'SeAT-ManagerCore/EcosystemVersionChecker',
                    ])
                    ->get($url);

                if (!$response->successful()) {
                    // 404 from Packagist means the package isn't registered
                    // there yet — the maintainer hasn't submitted it. From
                    // the operator's perspective this is the same as
                    // "registered but no releases" (BB Manager's state):
                    // the plugin is real, it just doesn't have a stable
                    // tag yet. Treat as reachable + no_releases so the UI
                    // shows the friendly 'Coming soon' badge instead of
                    // the scarier 'Packagist unreachable'.
                    if ($response->status() === 404) {
                        Log::info("[Manager Core] EcosystemVersionChecker: Packagist 404 for {$package} — not yet submitted to Packagist");
                        return ['version' => null, 'reachable' => true, 'has_releases' => false];
                    }
                    Log::warning("[Manager Core] EcosystemVersionChecker: Packagist HTTP " . $response->status() . " for {$package}");
                    return ['version' => null, 'reachable' => false, 'has_releases' => false];
                }

                $data = $response->json();
                if (!is_array($data) || !isset($data['packages'][$package]) || !is_array($data['packages'][$package])) {
                    // Packagist replied 200 but the package isn't in the
                    // response — treat as reachable-but-no-releases since
                    // the upstream is healthy, the package just isn't
                    // tagged-and-published yet.
                    Log::info("[Manager Core] EcosystemVersionChecker: Packagist has no packages.{$package} entry yet");
                    return ['version' => null, 'reachable' => true, 'has_releases' => false];
                }

                // Packagist v2 returns versions descending. Iterate and grab
                // the first NON-dev version we find.
                foreach ($data['packages'][$package] as $release) {
                    $version = $release['version'] ?? '';
                    if ($version === '' || str_starts_with($version, 'dev-') || str_contains($version, '-dev')) {
                        continue;
                    }
                    return [
                        'version'      => ltrim((string) $version, 'v'),
                        'reachable'    => true,
                        'has_releases' => true,
                    ];
                }

                // Packagist reachable + package known, but only dev-* refs
                // present. Genuine "no stable release yet" state.
                return ['version' => null, 'reachable' => true, 'has_releases' => false];
            } catch (\Throwable $e) {
                Log::warning("[Manager Core] EcosystemVersionChecker: fetch failed for {$package}: " . $e->getMessage());
                return ['version' => null, 'reachable' => false, 'has_releases' => false];
            }
        });

        // Defensive: tolerate v1-shape cache entries that pre-date the
        // cache-prefix bump on installs where Cache::remember still has
        // stale data under the old key for any reason. If we ever see a
        // raw string, normalize it to the new shape.
        if (is_string($info)) {
            return ['version' => $info, 'reachable' => true, 'has_releases' => true];
        }
        if (!is_array($info) || !array_key_exists('version', $info)) {
            return ['version' => null, 'reachable' => false, 'has_releases' => false];
        }
        return $info;
    }

    /**
     * Construct the GitHub release URL for a given (package, version).
     * Assumes Matt's MattFalahe org convention; falls back to null on
     * anything unexpected so the badge doesn't link somewhere broken.
     */
    protected function buildReleaseUrl(string $package, string $version): ?string
    {
        // Format: "mattfalahe/structure-manager" → "Structure-Manager"
        // Matt's repo naming is mostly Title-Cased-Hyphen-Separated except
        // a few legacy lower-case ones. The redirect from lower-case
        // composer slug to actual repo handles either way, so just title-case.
        $parts = explode('/', $package);
        if (count($parts) !== 2 || $parts[0] !== 'mattfalahe') {
            return null;
        }
        $repoSlug = implode('-', array_map('ucfirst', explode('-', $parts[1])));
        return "https://github.com/MattFalahe/{$repoSlug}/releases/tag/{$version}";
    }

    /**
     * Build the documented return shape. Centralized so every code path
     * returns identical keys (consumers can rely on `$status['latest']`
     * etc. being present even on failure).
     */
    protected function shape(
        ?string $pluginKey,
        ?string $package,
        ?string $current,
        string $source,
        bool $isDevBranch,
        ?string $latest,
        string $status,
        string $message,
        ?string $releaseUrl = null
    ): array {
        return [
            'plugin_key'     => $pluginKey,
            'package'        => $package,
            'current'        => $current,
            'current_source' => $source,
            'is_dev_branch'  => $isDevBranch,
            'latest'         => $latest,
            'status'         => $status,
            'message'        => $message,
            'release_url'    => $releaseUrl,
        ];
    }
}
