<?php

namespace ManagerCore\Contracts;

use Illuminate\Support\Collection;
use ManagerCore\Models\EventSubscription;

/**
 * Public contract for the Manager Core event bus.
 *
 * Plugins should depend on this interface (or the Events facade) rather
 * than the concrete \ManagerCore\Services\EventBus class.
 */
interface EventBusInterface
{
    /**
     * Publish an event to all matching subscribers.
     *
     * @return array ['dispatched' => int, 'failed' => int, 'errors' => []]
     */
    public function publish(string $eventName, string $publisherPlugin, array $payload = []): array;

    /**
     * Subscribe to an event pattern via a PluginBridge capability handler.
     */
    public function subscribe(
        string $subscriberPlugin,
        string $eventPattern,
        string $handlerCapability,
        array $options = []
    ): EventSubscription;

    /**
     * Subscribe to an event pattern via a class-based handler (preferred).
     */
    public function subscribeHandler(
        string $subscriberPlugin,
        string $eventPattern,
        string $handlerClass,
        string $method = 'handle',
        array $options = []
    ): EventSubscription;

    /**
     * Register an in-process callable that handles events. Lost on restart.
     */
    public function listen(string $eventPattern, callable $handler, int $priority = 0): void;

    public function unsubscribe(string $subscriberPlugin, string $eventPattern): bool;

    public function unsubscribeAll(string $subscriberPlugin): int;

    public function getSubscriptions(string $subscriberPlugin): Collection;

    public function getAllSubscriptions(): Collection;

    public function getEventLog(int $limit = 50, ?string $eventName = null, ?string $publisher = null): Collection;

    public function getStatistics(): array;

    public function getFailedEvents(int $hoursBack = 24, int $limit = 100): Collection;

    public function retryFailedEvent(int $eventLogId): array;
}
