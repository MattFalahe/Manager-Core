<?php

namespace ManagerCore\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\PluginRegistry;

/**
 * PluginBridge - Central hub for inter-plugin communication
 *
 * This service allows Manager Core and other plugins to:
 * - Discover and register compatible plugins
 * - Share services and capabilities
 * - Communicate via events and method calls
 */
class PluginBridge
{
    /**
     * Registered plugins
     *
     * @var array
     */
    protected $plugins = [];

    /**
     * Plugin capabilities registry
     *
     * @var array
     */
    protected $capabilities = [];

    /**
     * Cache key for plugin discovery
     */
    const CACHE_KEY = 'manager_core_plugin_bridge_registry';

    /**
     * Discover and register all compatible plugins
     *
     * @return void
     */
    public function discover()
    {
        if (!config('manager-core.bridge.auto_discover', true)) {
            return;
        }

        $cacheDuration = config('manager-core.bridge.cache_duration', 60);

        $this->plugins = Cache::remember(self::CACHE_KEY, $cacheDuration * 60, function () {
            return $this->scanForPlugins();
        });

        Log::info('[Manager Core] Plugin Bridge discovered ' . count($this->plugins) . ' plugins');
    }

    /**
     * Scan for compatible plugins
     *
     * @return array
     */
    protected function scanForPlugins()
    {
        $discoveredPlugins = [];
        $compatiblePlugins = config('manager-core.bridge.compatible_plugins', []);

        foreach ($compatiblePlugins as $pluginNamespace) {
            $providerClass = $pluginNamespace . '\\' . $pluginNamespace . 'ServiceProvider';

            if (class_exists($providerClass)) {
                $pluginName = $this->getPluginName($pluginNamespace);

                $discoveredPlugins[$pluginName] = [
                    'namespace' => $pluginNamespace,
                    'provider' => $providerClass,
                    'active' => true,
                ];

                // Update database registry
                $this->updateRegistry($pluginName, $providerClass);

                Log::info("[Manager Core] Discovered plugin: {$pluginName}");
            }
        }

        return $discoveredPlugins;
    }

    /**
     * Update plugin registry in database
     *
     * @param string $pluginName
     * @param string $providerClass
     * @return void
     */
    protected function updateRegistry($pluginName, $providerClass)
    {
        PluginRegistry::updateOrCreate(
            ['plugin_name' => $pluginName],
            [
                'plugin_class' => $providerClass,
                'is_active' => true,
                'last_seen_at' => now(),
            ]
        );
    }

    /**
     * Get plugin name from namespace
     *
     * @param string $namespace
     * @return string
     */
    protected function getPluginName($namespace)
    {
        // Convert namespace to kebab-case name
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $namespace));
    }

    /**
     * Check if a plugin is available
     *
     * @param string $pluginName
     * @return bool
     */
    public function hasPlugin($pluginName)
    {
        return isset($this->plugins[$pluginName]) && $this->plugins[$pluginName]['active'];
    }

    /**
     * Get plugin information
     *
     * @param string $pluginName
     * @return array|null
     */
    public function getPlugin($pluginName)
    {
        return $this->plugins[$pluginName] ?? null;
    }

    /**
     * Get all registered plugins
     *
     * @return array
     */
    public function getPlugins()
    {
        return $this->plugins;
    }

    /**
     * Register a capability that this or another plugin provides
     *
     * @param string $pluginName
     * @param string $capability
     * @param callable $handler
     * @return void
     */
    public function registerCapability($pluginName, $capability, callable $handler)
    {
        if (!isset($this->capabilities[$pluginName])) {
            $this->capabilities[$pluginName] = [];
        }

        $this->capabilities[$pluginName][$capability] = $handler;

        Log::info("[Manager Core] Plugin '{$pluginName}' registered capability: {$capability}");
    }

    /**
     * Check if a plugin has a specific capability
     *
     * @param string $pluginName
     * @param string $capability
     * @return bool
     */
    public function hasCapability($pluginName, $capability)
    {
        return isset($this->capabilities[$pluginName][$capability]);
    }

    /**
     * Call a plugin capability
     *
     * @param string $pluginName
     * @param string $capability
     * @param mixed ...$args
     * @return mixed
     */
    public function call($pluginName, $capability, ...$args)
    {
        if (!$this->hasCapability($pluginName, $capability)) {
            Log::warning("[Manager Core] Capability '{$capability}' not found for plugin '{$pluginName}'");
            return null;
        }

        try {
            return call_user_func($this->capabilities[$pluginName][$capability], ...$args);
        } catch (\Exception $e) {
            Log::error("[Manager Core] Error calling {$pluginName}.{$capability}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Broadcast a notification to a specific plugin (if it has notification capability)
     *
     * @param string $pluginName
     * @param string $type
     * @param array $data
     * @return bool
     */
    public function notify($pluginName, $type, array $data)
    {
        if ($this->hasPlugin($pluginName) && $this->hasCapability($pluginName, 'notify')) {
            return $this->call($pluginName, 'notify', $type, $data);
        }

        return false;
    }

    /**
     * Clear plugin discovery cache
     *
     * @return void
     */
    public function clearCache()
    {
        Cache::forget(self::CACHE_KEY);
        Log::info('[Manager Core] Plugin Bridge cache cleared');
    }

    /**
     * Get plugin statistics
     *
     * @return array
     */
    public function getStatistics()
    {
        return [
            'total_plugins' => count($this->plugins),
            'active_plugins' => count(array_filter($this->plugins, fn($p) => $p['active'])),
            'total_capabilities' => array_sum(array_map('count', $this->capabilities)),
            'plugins' => $this->plugins,
        ];
    }
}
