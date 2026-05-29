<?php

namespace ManagerCore\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Static facade for the Manager Core plugin bridge.
 *
 * Usage:
 *   use ManagerCore\Facades\Bridge;
 *   Bridge::registerSelf('my-plugin', [...]);
 *   $value = Bridge::call('mining-manager', 'capability.name', $arg1);
 *
 * @method static void discover(bool $forceRefresh = false)
 * @method static bool hasPlugin(string $pluginName)
 * @method static array|null getPlugin(string $pluginName)
 * @method static array getPlugins()
 * @method static void registerCapability(string $pluginName, string $capability, callable $handler)
 * @method static bool hasCapability(string $pluginName, string $capability)
 * @method static mixed call(string $pluginName, string $capability, ...$args)
 * @method static mixed callOrFail(string $pluginName, string $capability, ...$args)
 * @method static \Throwable|null getLastError()
 * @method static bool notify(string $pluginName, string $type, array $data)
 * @method static array publishEvent(string $eventName, string $publisherPlugin, array $payload = [])
 * @method static void clearCache()
 * @method static array getStatistics()
 * @method static void registerSelf(string $pluginKey, array $info)
 */
class Bridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ManagerCore\Contracts\PluginBridgeInterface::class;
    }
}
