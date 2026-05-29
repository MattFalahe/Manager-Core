<?php

namespace ManagerCore\Exceptions;

/**
 * Thrown when a PluginBridge capability is requested that is not registered.
 *
 * This is distinct from a capability handler that throws — that exception
 * is caught and re-thrown as CapabilityCallException so callers can tell
 * "doesn't exist" from "exists but failed".
 */
class CapabilityNotFoundException extends \RuntimeException
{
    public function __construct(string $pluginName, string $capability)
    {
        parent::__construct("Capability '{$capability}' not registered for plugin '{$pluginName}'");
    }
}
