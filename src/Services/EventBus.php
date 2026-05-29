<?php

namespace ManagerCore\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use ManagerCore\Exceptions\CapabilityCallException;
use ManagerCore\Exceptions\CapabilityNotFoundException;
use ManagerCore\Jobs\ProcessEventJob;
use ManagerCore\Models\EventLog;
use ManagerCore\Models\EventSubscription;

/**
 * EventBus - Cross-plugin publish/subscribe event system
 *
 * The core "ecosystem glue" feature. Enables plugins to broadcast events
 * and other plugins to subscribe to them, without direct knowledge of each other.
 *
 * Plugins use this optionally:
 *
 *   if (class_exists('ManagerCore\Services\EventBus')) {
 *       app(\ManagerCore\Services\EventBus::class)->publish('mining.jackpot_detected', 'mining-manager', [...]);
 *   }
 */
class EventBus implements \ManagerCore\Contracts\EventBusInterface
{
    /**
     * In-memory runtime listeners (not persisted, die on restart)
     *
     * @var array<string, array<array{handler: callable, priority: int}>>
     */
    protected array $runtimeListeners = [];

    /**
     * Plugin Bridge for capability-based dispatching
     *
     * @var PluginBridge
     */
    protected PluginBridge $bridge;

    /**
     * Maximum publish recursion depth before throwing.
     * Prevents infinite event loops from a subscriber that publishes back.
     */
    const MAX_PUBLISH_DEPTH = 8;

    /**
     * Current recursion depth — incremented on entry to publish(),
     * decremented on exit. Per-process / per-singleton state.
     *
     * @var int
     */
    protected int $publishDepth = 0;

    /**
     * Stack of in-flight event names — used for cycle detection diagnostics.
     *
     * @var array<int, string>
     */
    protected array $publishStack = [];

    /**
     * Constructor
     */
    public function __construct(PluginBridge $bridge)
    {
        $this->bridge = $bridge;
    }

    /**
     * Publish an event to all matching subscribers
     *
     * @param string $eventName e.g., 'mining.tax_created'
     * @param string $publisherPlugin e.g., 'mining-manager'
     * @param array $payload Arbitrary event data
     * @return array ['dispatched' => int, 'failed' => int, 'errors' => []]
     */
    public function publish(string $eventName, string $publisherPlugin, array $payload = []): array
    {
        // C1/H8 input validation: reject oversized payloads to protect EventLog
        $maxBytes = (int) config('manager-core.events.max_payload_bytes', 524288);
        $payloadJson = json_encode($payload);
        if ($payloadJson !== false && strlen($payloadJson) > $maxBytes) {
            Log::warning("[Manager Core] Payload exceeds max_payload_bytes — rejected", [
                'event' => $eventName,
                'publisher' => $publisherPlugin,
                'size' => strlen($payloadJson),
                'max' => $maxBytes,
            ]);
            return [
                'dispatched' => 0,
                'failed' => 0,
                'errors' => [['error' => 'payload_too_large', 'size' => strlen($payloadJson), 'max' => $maxBytes]],
            ];
        }

        // C1: enforce publisher prefix allow-list (configurable)
        if (config('manager-core.events.enforce_publisher_prefix', true)) {
            $allowedPrefixes = config("manager-core.events.publisher_prefixes.{$publisherPlugin}");
            if (is_array($allowedPrefixes) && !empty($allowedPrefixes)) {
                $matched = false;
                foreach ($allowedPrefixes as $prefix) {
                    if (str_starts_with($eventName, $prefix)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    Log::warning("[Manager Core] Event publish rejected — name does not match publisher prefix", [
                        'event' => $eventName,
                        'publisher' => $publisherPlugin,
                        'allowed_prefixes' => $allowedPrefixes,
                    ]);
                    return [
                        'dispatched' => 0,
                        'failed' => 0,
                        'errors' => [['error' => 'publisher_prefix_not_allowed', 'allowed' => $allowedPrefixes]],
                    ];
                }
            }
        }

        // M6: idempotency — if payload carries an idempotency_key and a previous
        // publish of THE SAME EVENT NAME from the same publisher used it within
        // the dedup window, suppress.
        //
        // 2026-05-12 fix: include $eventName in the dedup lookup. Previously the
        // check was just (publisher_plugin, idempotency_key), which meant two
        // distinct events derived from the same source (e.g. SM's
        // structure_manager.timer.created + structure.alert.shield_reinforced,
        // both keyed by 'esi-notif:<id>' via source_reference) would coalesce —
        // whichever published first claimed the key, the other got silently
        // suppressed. That broke Mining Manager's structure.alert.* subscriber
        // for reinforce events because the timer.created event from the
        // TimerObserver always fires fractions of a millisecond earlier than
        // the alert publish.
        //
        // Semantic: an event's identity is (name, publisher, key). Two events
        // with different names but the same idempotency_key from the same
        // publisher are distinct events and should both publish. The same event
        // (same name) repeated within the window is still suppressed, which
        // preserves the original protection against ESI bucket replays,
        // worker restarts mid-dispatch, and fast-poll / sweep races.
        $idempotencyKey = $this->extractIdempotencyKey($payload);
        if ($idempotencyKey !== null && $this->isDuplicateEvent($publisherPlugin, $eventName, $idempotencyKey)) {
            Log::debug("[Manager Core] Event publish suppressed — duplicate idempotency_key", [
                'event' => $eventName,
                'publisher' => $publisherPlugin,
                'idempotency_key' => $idempotencyKey,
            ]);
            return [
                'dispatched' => 0,
                'failed' => 0,
                'errors' => [['error' => 'duplicate_idempotency_key', 'key' => $idempotencyKey]],
            ];
        }

        // H4: Recursion guard — prevent infinite publish loops from subscribers
        if ($this->publishDepth >= self::MAX_PUBLISH_DEPTH) {
            Log::error("[Manager Core] Event publish recursion limit hit", [
                'event' => $eventName,
                'publisher' => $publisherPlugin,
                'depth' => $this->publishDepth,
                'stack' => $this->publishStack,
            ]);
            return [
                'dispatched' => 0,
                'failed' => 0,
                'errors' => [['error' => 'recursion_limit_exceeded', 'stack' => $this->publishStack]],
            ];
        }

        $this->publishDepth++;
        $this->publishStack[] = $eventName;

        $dispatched = 0;
        $failed = 0;
        $errors = [];

        try {
            Log::debug("[Manager Core] Event published: {$eventName} by {$publisherPlugin}");

            // 1. Dispatch to persistent (DB) subscribers
            $subscribers = $this->resolveSubscribers($eventName);

            foreach ($subscribers as $subscription) {
                $cbKey = $this->circuitBreakerKey($subscription);

                // M12: skip subscribers in cooldown
                if ($this->isInCooldown($cbKey)) {
                    $errors[] = [
                        'subscriber' => $subscription->subscriber_plugin,
                        'capability' => $subscription->handler_capability,
                        'error' => 'circuit_open',
                    ];
                    continue;
                }

                try {
                    $this->dispatchToSubscriber($subscription, $eventName, $publisherPlugin, $payload);
                    $dispatched++;
                    $this->recordSubscriberSuccess($cbKey);
                } catch (CapabilityNotFoundException $e) {
                    // H2: Distinguish missing from thrown
                    $failed++;
                    $errors[] = [
                        'subscriber' => $subscription->subscriber_plugin,
                        'capability' => $subscription->handler_capability,
                        'error' => 'capability_not_registered',
                    ];
                    $this->recordSubscriberFailure($cbKey);
                } catch (CapabilityCallException $e) {
                    $failed++;
                    $errors[] = [
                        'subscriber' => $subscription->subscriber_plugin,
                        'capability' => $subscription->handler_capability,
                        'error' => $e->getMessage(),
                    ];
                    Log::error("[Manager Core] Event handler threw", [
                        'event' => $eventName,
                        'subscriber' => $subscription->subscriber_plugin,
                        'error' => $e->getMessage(),
                    ]);
                    $this->recordSubscriberFailure($cbKey);
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'subscriber' => $subscription->subscriber_plugin,
                        'capability' => $subscription->handler_capability,
                        'error' => $e->getMessage(),
                    ];
                    Log::error("[Manager Core] Event dispatch failed", [
                        'event' => $eventName,
                        'subscriber' => $subscription->subscriber_plugin,
                        'error' => $e->getMessage(),
                    ]);
                    $this->recordSubscriberFailure($cbKey);
                }
            }

            // 2. Dispatch to runtime listeners
            // L8: collect ALL matching runtime listeners across patterns FIRST, then
            // sort once globally by priority — same semantic as the persistent
            // listener path, where priority is a global ordering, not per-pattern.
            $matchedRuntime = [];
            foreach ($this->runtimeListeners as $pattern => $listeners) {
                if (!fnmatch($pattern, $eventName)) {
                    continue;
                }
                foreach ($listeners as $listener) {
                    $matchedRuntime[] = ['pattern' => $pattern, 'listener' => $listener];
                }
            }
            usort($matchedRuntime, fn($a, $b) => $b['listener']['priority'] <=> $a['listener']['priority']);

            foreach ($matchedRuntime as $entry) {
                try {
                    call_user_func($entry['listener']['handler'], $eventName, $publisherPlugin, $payload);
                    $dispatched++;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'subscriber' => 'runtime_listener',
                        'pattern' => $entry['pattern'],
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // 3. Log the event (wrapped — never let log failure mask successful dispatches)
            try {
                $this->logEvent($eventName, $publisherPlugin, $payload, $dispatched + $failed, $errors, $idempotencyKey);
            } catch (\Throwable $e) {
                Log::warning("[Manager Core] EventLog write failed: " . $e->getMessage());
            }

            return [
                'dispatched' => $dispatched,
                'failed' => $failed,
                'errors' => $errors,
            ];
        } finally {
            $this->publishDepth--;
            array_pop($this->publishStack);
        }
    }

    /**
     * Subscribe to events (persisted in DB, survives restarts)
     *
     * @param string $subscriberPlugin
     * @param string $eventPattern Exact name or wildcard (e.g., 'mining.*')
     * @param string $handlerCapability PluginBridge capability to call
     * @param array $options ['queued' => bool, 'queue' => string, 'priority' => int]
     * @return EventSubscription
     */
    public function subscribe(
        string $subscriberPlugin,
        string $eventPattern,
        string $handlerCapability,
        array $options = []
    ): EventSubscription {
        // L12: warn on dangerous match-all patterns
        if ($eventPattern === '*' || $eventPattern === '**' || $eventPattern === '?') {
            Log::warning("[Manager Core] Subscribing to dangerous match-all pattern '{$eventPattern}'", [
                'subscriber' => $subscriberPlugin,
                'recommendation' => 'Use a specific prefix like "mining.*" instead — match-all patterns receive every event from every plugin and amplify the failure blast radius.',
            ]);
        }

        // M1: idempotent subscribe — only update the row if attributes have actually changed.
        // Avoids bumping updated_at on every plugin boot and flooding logs.
        $newAttrs = [
            'is_queued' => $options['queued'] ?? false,
            'queue_name' => $options['queue'] ?? null,
            'priority' => $options['priority'] ?? 0,
            'is_active' => true,
        ];

        // L11: optional per-subscription timeout
        if (isset($options['timeout_seconds'])) {
            $newAttrs['timeout_seconds'] = max(1, min(600, (int) $options['timeout_seconds']));
        }

        $existing = EventSubscription::where('subscriber_plugin', $subscriberPlugin)
            ->where('event_pattern', $eventPattern)
            ->where('handler_capability', $handlerCapability)
            ->first();

        if ($existing) {
            $changed = false;
            foreach ($newAttrs as $k => $v) {
                if ($existing->$k != $v) {
                    $changed = true;
                    break;
                }
            }
            if ($changed) {
                $existing->update($newAttrs);
                Log::info("[Manager Core] Event subscription updated: {$subscriberPlugin} -> {$eventPattern} via {$handlerCapability}");
            }
            return $existing;
        }

        $subscription = EventSubscription::create(array_merge([
            'subscriber_plugin' => $subscriberPlugin,
            'event_pattern' => $eventPattern,
            'handler_capability' => $handlerCapability,
        ], $newAttrs));

        Log::info("[Manager Core] Event subscription created: {$subscriberPlugin} -> {$eventPattern} via {$handlerCapability}");

        return $subscription;
    }

    /**
     * Subscribe with a class-based handler instead of a PluginBridge capability
     *
     * @param string $subscriberPlugin
     * @param string $eventPattern
     * @param string $handlerClass Fully-qualified class name
     * @param string $method Method to call (default 'handle')
     * @param array $options ['queued' => bool, 'queue' => string, 'priority' => int]
     * @return EventSubscription
     */
    public function subscribeHandler(
        string $subscriberPlugin,
        string $eventPattern,
        string $handlerClass,
        string $method = 'handle',
        array $options = []
    ): EventSubscription {
        // L12: warn on dangerous match-all patterns
        if ($eventPattern === '*' || $eventPattern === '**' || $eventPattern === '?') {
            Log::warning("[Manager Core] subscribeHandler with dangerous match-all pattern '{$eventPattern}'", [
                'subscriber' => $subscriberPlugin,
                'handler' => $handlerClass . '@' . $method,
            ]);
        }

        // M1: idempotent — only update if changed.
        // L1: handler_capability holds a synthesized internal value of the form
        //     'class:Class@method' so the unique key (subscriber_plugin,
        //     event_pattern, handler_capability) doesn't collide with capability
        //     subscriptions for the same plugin+pattern. Treat handler_capability
        //     as INTERNAL when handler_class is set (see EventSubscription model).
        $capabilityKey = 'class:' . $handlerClass . '@' . $method;
        $newAttrs = [
            'handler_class' => $handlerClass,
            'handler_method' => $method,
            'is_queued' => $options['queued'] ?? false,
            'queue_name' => $options['queue'] ?? null,
            'priority' => $options['priority'] ?? 0,
            'is_active' => true,
        ];

        // L11: per-subscription timeout
        if (isset($options['timeout_seconds'])) {
            $newAttrs['timeout_seconds'] = max(1, min(600, (int) $options['timeout_seconds']));
        }

        $existing = EventSubscription::where('subscriber_plugin', $subscriberPlugin)
            ->where('event_pattern', $eventPattern)
            ->where('handler_capability', $capabilityKey)
            ->first();

        if ($existing) {
            $changed = false;
            foreach ($newAttrs as $k => $v) {
                if ($existing->$k != $v) {
                    $changed = true;
                    break;
                }
            }
            if ($changed) {
                $existing->update($newAttrs);
                Log::info("[Manager Core] Event handler subscription updated: {$subscriberPlugin} -> {$eventPattern} via {$handlerClass}@{$method}");
            }
            return $existing;
        }

        $subscription = EventSubscription::create(array_merge([
            'subscriber_plugin' => $subscriberPlugin,
            'event_pattern' => $eventPattern,
            'handler_capability' => $capabilityKey,
        ], $newAttrs));

        Log::info("[Manager Core] Event handler subscription created: {$subscriberPlugin} -> {$eventPattern} via {$handlerClass}@{$method}");

        return $subscription;
    }

    /**
     * Subscribe with a runtime callable (not persisted — dies on restart)
     *
     * @param string $eventPattern
     * @param callable $handler fn(string $eventName, string $publisher, array $payload)
     * @param int $priority Higher = called first
     * @return void
     */
    public function listen(string $eventPattern, callable $handler, int $priority = 0): void
    {
        $this->runtimeListeners[$eventPattern][] = [
            'handler' => $handler,
            'priority' => $priority,
        ];
    }

    /**
     * Remove a persistent subscription
     *
     * @param string $subscriberPlugin
     * @param string $eventPattern
     * @return bool
     */
    public function unsubscribe(string $subscriberPlugin, string $eventPattern): bool
    {
        $deleted = EventSubscription::where('subscriber_plugin', $subscriberPlugin)
            ->where('event_pattern', $eventPattern)
            ->delete();

        if ($deleted > 0) {
            Log::info("[Manager Core] Event subscription removed: {$subscriberPlugin} -> {$eventPattern}");
        }

        return $deleted > 0;
    }

    /**
     * Remove all subscriptions for a plugin
     *
     * @param string $subscriberPlugin
     * @return int Number of subscriptions removed
     */
    public function unsubscribeAll(string $subscriberPlugin): int
    {
        $count = EventSubscription::where('subscriber_plugin', $subscriberPlugin)->delete();

        if ($count > 0) {
            Log::info("[Manager Core] Removed all {$count} event subscriptions for {$subscriberPlugin}");
        }

        return $count;
    }

    /**
     * Get all subscriptions for a plugin
     *
     * @param string $subscriberPlugin
     * @return Collection
     */
    public function getSubscriptions(string $subscriberPlugin): Collection
    {
        return EventSubscription::where('subscriber_plugin', $subscriberPlugin)
            ->orderBy('priority', 'desc')
            ->get();
    }

    /**
     * Get all active subscriptions
     *
     * @return Collection
     */
    public function getAllSubscriptions(): Collection
    {
        return EventSubscription::active()
            ->orderBy('subscriber_plugin')
            ->orderBy('priority', 'desc')
            ->get();
    }

    /**
     * Get recent event log entries
     *
     * @param int $limit
     * @param string|null $eventName Filter by event name
     * @param string|null $publisher Filter by publisher
     * @return Collection
     */
    public function getEventLog(int $limit = 50, ?string $eventName = null, ?string $publisher = null): Collection
    {
        $query = EventLog::orderBy('created_at', 'desc');

        if ($eventName) {
            $query->where('event_name', 'LIKE', '%' . $eventName . '%');
        }

        if ($publisher) {
            $query->where('publisher_plugin', $publisher);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get event bus statistics
     *
     * @return array
     */
    /**
     * M4: Surface failed events for retry / alerting.
     *
     * Returns a Collection of EventLog rows where status != 'dispatched'
     * within the given window. Useful for the diagnostic UI and the
     * cleanup-events command's --report flag.
     *
     * @param int $hoursBack
     * @param int $limit
     * @return Collection
     */
    public function getFailedEvents(int $hoursBack = 24, int $limit = 100): Collection
    {
        return EventLog::where('status', '!=', 'dispatched')
            ->where('created_at', '>=', now()->subHours($hoursBack))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * M4: Re-publish a failed event by EventLog ID. The publisher and event_name
     * are reused from the log entry; the original payload is replayed.
     *
     * Returns the same shape as publish().
     *
     * @param int $eventLogId
     * @return array
     */
    public function retryFailedEvent(int $eventLogId): array
    {
        $log = EventLog::find($eventLogId);
        if (!$log) {
            return [
                'dispatched' => 0,
                'failed' => 0,
                'errors' => [['error' => 'event_log_not_found', 'id' => $eventLogId]],
            ];
        }

        // Strip any idempotency_key from the payload so the retry isn't suppressed
        // as a duplicate of itself.
        $payload = is_array($log->payload) ? $log->payload : [];
        unset($payload['idempotency_key']);
        if (isset($payload['_meta']['idempotency_key'])) {
            unset($payload['_meta']['idempotency_key']);
        }

        Log::info("[Manager Core] Manual retry of failed event log #{$eventLogId}", [
            'event' => $log->event_name,
            'publisher' => $log->publisher_plugin,
        ]);

        return $this->publish($log->event_name, $log->publisher_plugin, $payload);
    }

    public function getStatistics(): array
    {
        return [
            'total_subscriptions' => EventSubscription::count(),
            'active_subscriptions' => EventSubscription::active()->count(),
            'subscribing_plugins' => EventSubscription::active()->distinct('subscriber_plugin')->count('subscriber_plugin'),
            'events_today' => EventLog::where('created_at', '>=', now()->startOfDay())->count(),
            'events_failed_today' => EventLog::where('created_at', '>=', now()->startOfDay())
                ->where('status', '!=', 'dispatched')
                ->count(),
            'runtime_listeners' => count($this->runtimeListeners),
        ];
    }

    /**
     * Resolve which persistent subscribers match a given event name
     *
     * C3 fix: pre-filter at the SQL level (exact match OR LIKE-translated wildcard)
     * BEFORE applying the per-event listener cap. The previous implementation loaded
     * the top 50 active subscriptions GLOBALLY by priority and then filtered with
     * fnmatch in PHP — meaning a high-priority unrelated subscription could starve
     * out the only matching subscriber for a given event.
     *
     * @param string $eventName
     * @return Collection
     */
    protected function resolveSubscribers(string $eventName): Collection
    {
        $maxListeners = (int) config('manager-core.events.max_listeners_per_event', 50);

        // Build candidates in two passes — first DB-side narrow, then PHP fnmatch.
        $query = EventSubscription::active()
            ->where(function ($q) use ($eventName) {
                // Exact match
                $q->where('event_pattern', $eventName);

                // Wildcard pattern match — fetch any pattern containing '*' or '?'
                // and let fnmatch do the precise check. This is broader than
                // strictly necessary but keeps the SQL fast (just a LIKE on a string).
                $q->orWhere('event_pattern', 'LIKE', '%*%');
                $q->orWhere('event_pattern', 'LIKE', '%?%');
            })
            ->orderBy('priority', 'desc');

        // Apply the per-event cap AFTER the pattern narrowing
        $candidates = $query->limit($maxListeners * 4)->get();

        // Final precise filter for wildcard subscriptions
        $matching = $candidates->filter(fn(EventSubscription $sub) => $sub->matches($eventName));

        // Apply the actual per-event limit on the matched set
        return $matching->take($maxListeners)->values();
    }

    /**
     * Dispatch to a single subscriber (sync or queued)
     *
     * @param EventSubscription $subscription
     * @param string $eventName
     * @param string $publisherPlugin
     * @param array $payload
     * @return void
     */
    protected function dispatchToSubscriber(
        EventSubscription $subscription,
        string $eventName,
        string $publisherPlugin,
        array $payload
    ): void {
        if ($subscription->is_queued) {
            // Dispatch as queued job — non-blocking. L10: pass the subscription
            // instance so ProcessEventJob snapshots its dispatch fields at this
            // moment; mid-flight subscription edits won't change in-flight jobs.
            $job = new ProcessEventJob(
                $eventName,
                $publisherPlugin,
                $payload,
                $subscription->id,
                $subscription
            );

            if ($subscription->queue_name) {
                $job->onQueue($subscription->queue_name);
            }

            dispatch($job);

            Log::debug("[Manager Core] Queued event dispatch: {$eventName} -> {$subscription->subscriber_plugin}");
            return;
        }

        // H3: Synchronous dispatch — track wall-clock and log slow handlers.
        // PHP can't preempt user code without pcntl_alarm (not available in FPM),
        // but we can flag offenders so operators see what's blocking publishers.
        $slowThresholdMs = (int) config('manager-core.events.sync_slow_threshold_ms', 1000);
        $start = microtime(true);

        try {
            if ($subscription->handler_class) {
                // Class-based handler — use callOrFail semantics manually
                if (!class_exists($subscription->handler_class)) {
                    throw new CapabilityNotFoundException(
                        $subscription->subscriber_plugin,
                        'class:' . $subscription->handler_class
                    );
                }
                $handler = app($subscription->handler_class);
                $method = $subscription->handler_method ?? 'handle';

                try {
                    $handler->$method($eventName, $publisherPlugin, $payload);
                } catch (\Throwable $e) {
                    throw new CapabilityCallException(
                        $subscription->subscriber_plugin,
                        $subscription->handler_class . '@' . $method,
                        $e
                    );
                }
            } else {
                // Capability-based dispatch — use callOrFail so missing-vs-thrown is visible
                $this->bridge->callOrFail(
                    $subscription->subscriber_plugin,
                    $subscription->handler_capability,
                    $eventName,
                    $publisherPlugin,
                    $payload
                );
            }
        } finally {
            $elapsedMs = (int) ((microtime(true) - $start) * 1000);
            if ($elapsedMs >= $slowThresholdMs) {
                Log::warning("[Manager Core] Slow sync event handler", [
                    'event' => $eventName,
                    'subscriber' => $subscription->subscriber_plugin,
                    'handler' => $subscription->handler_class
                        ? $subscription->handler_class . '@' . ($subscription->handler_method ?? 'handle')
                        : $subscription->handler_capability,
                    'duration_ms' => $elapsedMs,
                    'threshold_ms' => $slowThresholdMs,
                    'recommendation' => 'consider is_queued=true for this subscription',
                ]);
            }
        }
    }

    /**
     * Log an event dispatch
     *
     * @param string $eventName
     * @param string $publisherPlugin
     * @param array $payload
     * @param int $subscriberCount
     * @param array $errors
     * @return void
     */
    protected function logEvent(
        string $eventName,
        string $publisherPlugin,
        array $payload,
        int $subscriberCount,
        array $errors,
        ?string $idempotencyKey = null
    ): void {
        $status = 'dispatched';
        if (!empty($errors) && $subscriberCount > count($errors)) {
            $status = 'partial_failure';
        } elseif (!empty($errors)) {
            $status = 'failed';
        }

        // M11: defense in depth — even if publish() let an oversized payload through
        // (config edited mid-request), truncate at the row level so we never blow up
        // MySQL's max_allowed_packet.
        $rowMaxBytes = (int) config('manager-core.events.row_max_payload_bytes', 65536);
        $persistedPayload = $this->truncatePayloadForLog($payload, $rowMaxBytes);

        EventLog::create([
            'event_name' => $eventName,
            'publisher_plugin' => $publisherPlugin,
            'idempotency_key' => $idempotencyKey,
            'payload' => $persistedPayload,
            'subscriber_count' => $subscriberCount,
            'status' => $status,
            'errors' => !empty($errors) ? $errors : null,
            'created_at' => now(),
        ]);
    }

    /**
     * M11: Truncate a payload that would otherwise blow up the EventLog row.
     * Replaces the payload with a placeholder + size info if over the limit.
     */
    protected function truncatePayloadForLog(array $payload, int $maxBytes): array
    {
        $json = json_encode($payload);
        if ($json !== false && strlen($json) <= $maxBytes) {
            return $payload;
        }

        return [
            '_truncated' => true,
            '_original_size' => $json !== false ? strlen($json) : 0,
            '_max_bytes' => $maxBytes,
            '_keys' => array_keys($payload),
            '_note' => 'Payload exceeded row_max_payload_bytes — full payload not persisted',
        ];
    }

    // ---------------------------------------------------------------------
    // M6: Idempotency helpers
    // ---------------------------------------------------------------------

    /**
     * Extract an idempotency key from a payload.
     *
     * Inspection order (first match wins):
     *   1. payload['idempotency_key']           — canonical envelope field
     *   2. payload['_meta']['idempotency_key']  — project event-contract envelope
     *   3. payload['source_reference']          — STABLE source identifier (preferred for SM/MM)
     *   4. payload['event_id']                  — UUID per publish (least useful — only dedupes literal duplicate publishes)
     *
     * The 3rd / 4th fallbacks were added so plugins that already populate a
     * stable source identifier (Structure Manager uses
     * `source_reference = 'esi-notif:<id>'` etc.) get duplicate-publish
     * suppression for free, without having to add an extra field. Any plugin
     * can opt OUT of this by simply not setting either field.
     *
     * Plugins should still prefer the canonical idempotency_key going forward;
     * the fallbacks exist purely for graceful adoption.
     */
    protected function extractIdempotencyKey(array $payload): ?string
    {
        if (isset($payload['idempotency_key']) && is_string($payload['idempotency_key']) && $payload['idempotency_key'] !== '') {
            return substr($payload['idempotency_key'], 0, 128);
        }
        if (isset($payload['_meta']['idempotency_key']) && is_string($payload['_meta']['idempotency_key']) && $payload['_meta']['idempotency_key'] !== '') {
            return substr($payload['_meta']['idempotency_key'], 0, 128);
        }
        // Honor stable source references (SM uses 'esi-notif:NNN', 'fuel:NNN', etc.)
        if (isset($payload['source_reference']) && is_string($payload['source_reference']) && $payload['source_reference'] !== '') {
            return substr($payload['source_reference'], 0, 128);
        }
        // Last-ditch: per-publish UUID (suppresses literal duplicate publishes only).
        if (isset($payload['event_id']) && is_string($payload['event_id']) && $payload['event_id'] !== '') {
            return substr($payload['event_id'], 0, 128);
        }
        return null;
    }

    /**
     * Has this (publisher, event_name, idempotency_key) tuple been seen within
     * the dedup window? Window default: 1 hour. Configurable via
     * 'events.idempotency_window_seconds'.
     *
     * 2026-05-12: event_name is part of the dedup tuple. See the note at the
     * caller (around the publish() entry-point) for the rationale — TL;DR:
     * two distinct event types from the same publisher that happen to share
     * an idempotency_key (e.g. SM's source_reference 'esi-notif:<id>' is the
     * same for the timer.created event and the structure.alert.* event
     * derived from the same notification) used to coalesce, which silently
     * dropped one of them.
     */
    protected function isDuplicateEvent(string $publisherPlugin, string $eventName, string $idempotencyKey): bool
    {
        $windowSeconds = (int) config('manager-core.events.idempotency_window_seconds', 3600);

        try {
            return EventLog::where('publisher_plugin', $publisherPlugin)
                ->where('event_name', $eventName)
                ->where('idempotency_key', $idempotencyKey)
                ->where('created_at', '>=', now()->subSeconds($windowSeconds))
                ->exists();
        } catch (\Throwable $e) {
            // Migration may not have run yet — fail open
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // M12: Circuit breaker for failing subscribers
    // ---------------------------------------------------------------------

    /**
     * Compute a stable cache key for a subscription's circuit breaker.
     */
    protected function circuitBreakerKey(EventSubscription $sub): string
    {
        return 'mc_cb_' . md5($sub->subscriber_plugin . '|' . $sub->handler_capability);
    }

    /**
     * Is this subscriber currently in cooldown? Skip dispatch if so.
     */
    protected function isInCooldown(string $cbKey): bool
    {
        return (bool) \Illuminate\Support\Facades\Cache::get($cbKey . '_open');
    }

    /**
     * Reset failure counter and clear any open circuit on a successful dispatch.
     */
    protected function recordSubscriberSuccess(string $cbKey): void
    {
        \Illuminate\Support\Facades\Cache::forget($cbKey . '_failures');
        \Illuminate\Support\Facades\Cache::forget($cbKey . '_open');
    }

    /**
     * Increment failure count. After N failures within the window, open the circuit
     * (skip dispatch for the cooldown period). Defaults: 5 failures within 5 min,
     * cooldown 5 min. Configurable via events.circuit_breaker.* config.
     */
    protected function recordSubscriberFailure(string $cbKey): void
    {
        $threshold = (int) config('manager-core.events.circuit_breaker.failure_threshold', 5);
        $windowSeconds = (int) config('manager-core.events.circuit_breaker.window_seconds', 300);
        $cooldownSeconds = (int) config('manager-core.events.circuit_breaker.cooldown_seconds', 300);

        $count = (int) \Illuminate\Support\Facades\Cache::get($cbKey . '_failures', 0);
        $count++;
        \Illuminate\Support\Facades\Cache::put($cbKey . '_failures', $count, $windowSeconds);

        if ($count >= $threshold) {
            \Illuminate\Support\Facades\Cache::put($cbKey . '_open', true, $cooldownSeconds);
            Log::warning("[Manager Core] Circuit breaker opened for subscriber", [
                'key' => $cbKey,
                'failures' => $count,
                'cooldown_seconds' => $cooldownSeconds,
            ]);
        }
    }

    // ---------------------------------------------------------------------
    // M25: Visibility filter helpers
    // ---------------------------------------------------------------------

    /**
     * Determine if a payload's visibility scope (corporation_id / role_id)
     * permits delivery to a given user.
     *
     * PUBLIC API for subscribers — currently no in-tree caller because no
     * subscriber yet fans out per-user (Discord Pings will be the first).
     * Documented and intentionally retained: provides the canonical
     * implementation of the visibility-scoping contract so every future
     * per-user subscriber agrees on what `corporation_id` / `role_id` mean.
     *
     * Subscribers (e.g. Discord Pings) call this in their handler to decide
     * whether to render/forward an event for a particular user. Returns true
     * if the payload has no scope (global) OR the user is in the scoped corp.
     *
     * @param array $payload     Event payload (may contain corporation_id, role_id)
     * @param array $userContext ['user_id' => int, 'corporation_ids' => int[], 'role_ids' => int[]]
     * @return bool
     */
    public static function shouldDeliverToUser(array $payload, array $userContext): bool
    {
        $payloadCorpId = $payload['corporation_id'] ?? null;
        $payloadRoleId = $payload['role_id'] ?? null;

        if ($payloadCorpId === null && $payloadRoleId === null) {
            return true; // global scope
        }

        if ($payloadCorpId !== null) {
            $userCorps = (array) ($userContext['corporation_ids'] ?? []);
            if (!in_array((int) $payloadCorpId, array_map('intval', $userCorps), true)) {
                return false;
            }
        }

        if ($payloadRoleId !== null) {
            $userRoles = (array) ($userContext['role_ids'] ?? []);
            if (!in_array((int) $payloadRoleId, array_map('intval', $userRoles), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * M26: Sanitize Discord-bound text fields in a payload.
     *
     * Strips/escapes @everyone, @here, role mentions, channel mentions,
     * and disables markdown. Subscribers wrapping payload values into
     * Discord webhook content should call this defensively.
     *
     * Only sanitizes string values; non-string fields pass through.
     */
    public static function sanitizeForDiscord(array $payload): array
    {
        // A1: zero-width-space char (U+200B). PHP only interprets \u{XXXX}
        // escapes in DOUBLE-quoted strings — the previous single-quoted
        // version wrote the literal text "\u{200B}" into payloads instead
        // of the actual code point, so the mention/backtick neutralization
        // was visually broken and only worked by accident on @everyone.
        $zwsp = "\u{200B}";

        $sanitize = function ($value) use (&$sanitize, $zwsp) {
            if (is_string($value)) {
                // Neutralize mass mentions
                $value = str_replace(
                    ['@everyone', '@here'],
                    ['@' . $zwsp . 'everyone', '@' . $zwsp . 'here'],
                    $value
                );
                // Escape role/channel mentions: <@&123>, <#456>, <@!789>
                $value = preg_replace('/<(@!?&?|#)(\d+)>/', '\\<$1$2\\>', $value);
                // Break out of code blocks (Discord triple-backtick fence)
                $value = str_replace('```', '`' . $zwsp . '``', $value);
                return $value;
            }
            if (is_array($value)) {
                return array_map($sanitize, $value);
            }
            return $value;
        };

        return $sanitize($payload);
    }

    /**
     * Convenience: publish + auto-sanitize Discord-bound string fields.
     *
     * Subscribers (e.g. Discord Pings) that render payload values verbatim
     * benefit from defensive sanitization at the publisher boundary so a
     * hostile structure name like "@everyone HQ" can't trigger mass pings.
     *
     * Functionally equivalent to publish() but pre-runs sanitizeForDiscord
     * on the payload. Recommended for any event whose payload includes
     * EVE-name strings, attacker names, structure names, operator notes,
     * or any other field that isn't strictly numeric/enum.
     */
    public function publishSanitized(string $eventName, string $publisherPlugin, array $payload = []): array
    {
        return $this->publish($eventName, $publisherPlugin, self::sanitizeForDiscord($payload));
    }
}
