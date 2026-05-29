<?php

namespace ManagerCore\Helpers;

use Illuminate\Support\Facades\Cache;
use ManagerCore\Models\Setting;

/**
 * Settings helper — reads DB setting first, falls back to config().
 *
 * H8 fix: Many fields in the Settings UI used to be saved to the DB but
 * never read by the runtime (which read config() directly). This helper
 * wires DB settings into the actual readers so the UI controls take effect.
 *
 * Cached for 60 seconds per process via Laravel's cache to keep DB read
 * pressure low while still letting setting changes propagate quickly.
 */
class Settings
{
    /**
     * Cache TTL in seconds for DB lookups.
     */
    const CACHE_TTL = 60;

    /**
     * Index key — list of every setting key we've ever cached, so flushAll()
     * can find them. Updated on every get().
     */
    const INDEX_KEY = 'mc_setting_index';

    /**
     * Get a value, checking the DB Setting table first, then falling back
     * to the manager-core config file, then to the supplied default.
     *
     * @param string $settingKey Key in manager_core_settings (e.g., 'pricing.cache_ttl')
     * @param string|null $configKey Key in manager-core config (e.g., 'cache.prices_duration'). Pass null if no config fallback.
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $settingKey, ?string $configKey = null, $default = null)
    {
        $cacheKey = 'mc_setting_' . md5($settingKey);

        // Track the key in the index so flushAll() can clear it later.
        // Cheap — single Redis SADD-equivalent on cache miss only.
        $value = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($settingKey) {
            self::trackKey($settingKey);
            return Setting::get($settingKey, '__MC_NOT_SET__');
        });

        if ($value !== '__MC_NOT_SET__' && $value !== null) {
            return $value;
        }

        if ($configKey !== null) {
            $configValue = config('manager-core.' . $configKey);
            if ($configValue !== null) {
                return $configValue;
            }
        }

        return $default;
    }

    /**
     * Force re-read from DB on next get() — use after a settings save.
     */
    public static function forget(string $settingKey): void
    {
        Cache::forget('mc_setting_' . md5($settingKey));
    }

    /**
     * Clear ALL cached MC setting reads — call after a bulk settings update
     * (e.g. import from JSON, factory reset). For per-key invalidation use
     * forget($key) which is faster.
     *
     * PUBLIC API. No in-tree caller today; intentionally retained for the
     * future "import settings from JSON" / "factory reset" admin actions.
     * The legitimate per-key path is forget(); this method exists for the
     * cases where the caller doesn't know which keys changed.
     */
    public static function flushAll(): void
    {
        try {
            $index = Cache::get(self::INDEX_KEY, []);
            if (!is_array($index)) {
                $index = [];
            }
            foreach ($index as $key) {
                Cache::forget('mc_setting_' . md5($key));
            }
            Cache::forget(self::INDEX_KEY);
        } catch (\Throwable $e) {
            // Cache backend hiccup — entries will expire naturally on TTL.
        }
    }

    /**
     * Track a key in the in-cache index. Best-effort; non-fatal on failure.
     */
    protected static function trackKey(string $settingKey): void
    {
        try {
            $index = Cache::get(self::INDEX_KEY, []);
            if (!is_array($index)) {
                $index = [];
            }
            if (!in_array($settingKey, $index, true)) {
                $index[] = $settingKey;
                // 1-day TTL on the index — re-tracked on next get() if it expires
                Cache::put(self::INDEX_KEY, $index, 86400);
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }
}
