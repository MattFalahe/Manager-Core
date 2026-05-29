<?php

namespace ManagerCore;

/**
 * Lightweight namespace-level helper for plugins integrating with Manager Core.
 *
 * Exposes a stable VERSION constant and an isReady() check so consumer plugins
 * can gate behavior:
 *
 *   if (\ManagerCore\ManagerCore::isReady()) {
 *       // Use MC services
 *   }
 *
 *   if (version_compare(\ManagerCore\ManagerCore::VERSION, '1.0.0', '>=')) {
 *       // Use a feature only present in 1.0.0+
 *   }
 */
class ManagerCore
{
    /**
     * Public version of Manager Core. Bump on each release.
     *
     * Plugins can compare against this constant to gate features that depend
     * on a newer MC version. Keep in sync with composer.json.
     */
    const VERSION = '1.0.0';

    /**
     * The major.minor channel — useful for "any 1.x" gating.
     */
    const CHANNEL = '1.0';

    /**
     * Is Manager Core fully booted and ready to serve calls?
     *
     * Returns true only when:
     *   - The service-provider class is loadable (composer has it)
     *   - The IoC container has the core service singletons bound
     *   - The DB connection works AND core tables exist
     *
     * Subscribers should use this in addition to class_exists() before
     * calling MC services from their boot() methods if they want to be
     * safe across install / migration / partial-deploy edge cases.
     */
    public static function isReady(): bool
    {
        if (!class_exists(\ManagerCore\ManagerCoreServiceProvider::class)) {
            return false;
        }

        try {
            // Container must have core singletons bound
            $required = [
                \ManagerCore\Services\PluginBridge::class,
                \ManagerCore\Services\EventBus::class,
                \ManagerCore\Services\PricingService::class,
            ];

            foreach ($required as $cls) {
                if (!app()->bound($cls) && !class_exists($cls)) {
                    return false;
                }
            }

            // Sanity-check the DB — core table must exist
            return \Illuminate\Support\Facades\Schema::hasTable('manager_core_plugin_registry');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Convenience guard helper for try/catch use:
     *
     *   \ManagerCore\ManagerCore::tryCall(fn() => app(EventBus::class)->publish(...));
     */
    public static function tryCall(callable $fn, $default = null)
    {
        if (!self::isReady()) {
            return $default;
        }
        try {
            return $fn();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Manager Core] tryCall caught: ' . $e->getMessage());
            return $default;
        }
    }
}
