<?php

namespace ManagerCore\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Static facade for the Manager Core event bus.
 *
 * Usage:
 *   use ManagerCore\Facades\Events;
 *   Events::publish('mining.tax_created', 'mining-manager', $payload);
 *
 * @method static array publish(string $eventName, string $publisherPlugin, array $payload = [])
 * @method static \ManagerCore\Models\EventSubscription subscribe(string $subscriberPlugin, string $eventPattern, string $handlerCapability, array $options = [])
 * @method static \ManagerCore\Models\EventSubscription subscribeHandler(string $subscriberPlugin, string $eventPattern, string $handlerClass, string $method = 'handle', array $options = [])
 * @method static void listen(string $eventPattern, callable $handler, int $priority = 0)
 * @method static bool unsubscribe(string $subscriberPlugin, string $eventPattern)
 * @method static int unsubscribeAll(string $subscriberPlugin)
 * @method static \Illuminate\Support\Collection getSubscriptions(string $subscriberPlugin)
 * @method static \Illuminate\Support\Collection getAllSubscriptions()
 * @method static \Illuminate\Support\Collection getEventLog(int $limit = 50, ?string $eventName = null, ?string $publisher = null)
 * @method static array getStatistics()
 * @method static \Illuminate\Support\Collection getFailedEvents(int $hoursBack = 24, int $limit = 100)
 * @method static array retryFailedEvent(int $eventLogId)
 */
class Events extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ManagerCore\Contracts\EventBusInterface::class;
    }
}
