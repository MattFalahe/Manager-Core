<?php

namespace ManagerCore\Exceptions;

/**
 * Thrown when a PluginBridge capability is registered but its handler threw.
 *
 * Wraps the original exception so callers can tell "exists but failed" from
 * "doesn't exist" (CapabilityNotFoundException).
 */
class CapabilityCallException extends \RuntimeException
{
    public function __construct(string $pluginName, string $capability, \Throwable $previous)
    {
        parent::__construct(
            "Capability '{$capability}' on plugin '{$pluginName}' threw: " . $previous->getMessage(),
            0,
            $previous
        );
    }
}
