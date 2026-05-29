<?php

namespace ManagerCore\Contracts;

/**
 * Public contract for the Manager Core plugin bridge.
 */
interface PluginBridgeInterface
{
    public function discover(bool $forceRefresh = false);

    public function hasPlugin($pluginName);

    public function getPlugin($pluginName);

    public function getPlugins();

    public function registerCapability($pluginName, $capability, callable $handler);

    public function hasCapability($pluginName, $capability);

    public function call($pluginName, $capability, ...$args);

    public function callOrFail($pluginName, $capability, ...$args);

    /**
     * Returns the throwable raised by the most recent call() that returned null,
     * or null if call() succeeded or threw something call() doesn't catch.
     *
     * Lets callers using the lenient call() distinguish "capability not found"
     * (CapabilityNotFoundException) from "capability handler threw"
     * (CapabilityCallException) without switching to callOrFail().
     */
    public function getLastError(): ?\Throwable;

    public function notify($pluginName, $type, array $data);

    public function publishEvent(string $eventName, string $publisherPlugin, array $payload = []): array;

    public function clearCache();

    public function getStatistics();

    public function registerSelf(string $pluginKey, array $info): void;
}
