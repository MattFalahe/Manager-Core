<?php

namespace ManagerCore\Services\ESI;

use Illuminate\Support\Facades\Log;

/**
 * Plugin subscription registry for ESI notifications.
 *
 * Plugins register handlers for specific CCP notification types at boot time.
 * When the shared polling job discovers a new notification, the registry
 * routes it to every plugin that registered for that type.
 *
 * Usage from a plugin's ServiceProvider::boot():
 *
 *     if (class_exists(\ManagerCore\Services\ESI\EsiNotificationRegistry::class)) {
 *         app(\ManagerCore\Services\ESI\EsiNotificationRegistry::class)->register(
 *             ['StructureUnderAttack', 'StructureLostShields', ...],
 *             \MyPlugin\Handlers\MyStructureHandler::class
 *         );
 *     }
 *
 * Handler contract: any class with a static `handle($notification): void` method.
 * The $notification argument is a \ManagerCore\Models\ESI\EsiNotification instance.
 *
 * Registered as a singleton so all plugins contribute to the same in-memory map
 * during a single request/job lifecycle.
 */
class EsiNotificationRegistry
{
    /**
     * Map of CCP notification type => list of handler entries.
     * Each entry: ['class' => FQN, 'queued' => bool, 'queue' => ?string, 'plugin' => string]
     *
     * @var array<string, array<int, array>>
     */
    protected array $handlers = [];

    /**
     * Plugin metadata — tracks which plugin registered which types.
     * Useful for the diagnostic dashboard to show per-plugin subscriptions.
     *
     * @var array<int, array{plugin: string, types: array, handler: string, queued: bool}>
     */
    protected array $registrations = [];

    /**
     * Per-handler dispatch latency stats (in-memory, per process).
     * Reported by getRegistrationsByPlugin() so operators can spot slow handlers.
     *
     * @var array<string, array{count: int, total_ms: int, max_ms: int, slow_count: int}>
     */
    protected array $handlerStats = [];

    /**
     * Slow handler threshold (ms). Handlers exceeding this are logged with a warning.
     */
    const SLOW_HANDLER_MS = 2000;

    /**
     * Register a handler class for one or more CCP notification types.
     *
     * H7: Handlers can opt into queued (async) dispatch via $options['queued'] = true.
     * Queued handlers don't block MC's PollEsiNotifications job — useful for handlers
     * that POST to external webhooks (Discord, Slack, etc.) and may have variable latency.
     *
     * @param string|array $types  One or many notification type strings (e.g. 'StructureUnderAttack')
     * @param string       $handlerClass Fully-qualified class name with a static handle() method
     * @param string|null  $pluginName Optional plugin identifier for the diagnostic UI
     * @param array        $options ['queued' => bool, 'queue' => string|null]
     */
    public function register($types, string $handlerClass, ?string $pluginName = null, array $options = []): void
    {
        $types = (array) $types;
        $resolvedPlugin = $pluginName ?? $this->inferPluginName($handlerClass);

        // C1 mitigation: per-plugin queued default lets operators force queued
        // dispatch for plugins whose handlers do slow work (Discord webhooks etc.)
        // without requiring a code change in the consumer plugin.
        // Lookup precedence:
        //   1. explicit ['queued' => bool] arg from the caller
        //   2. config('manager-core.events.handler_defaults.<plugin>.queued')
        //   3. global config('manager-core.events.handler_defaults.default.queued')
        //   4. false (sync — back-compat preserved)
        $callerQueued = $options['queued'] ?? null;
        if ($callerQueued !== null) {
            $queued = (bool) $callerQueued;
        } else {
            $perPlugin = config("manager-core.events.handler_defaults.{$resolvedPlugin}.queued");
            $globalDefault = config('manager-core.events.handler_defaults.default.queued');
            $queued = (bool) ($perPlugin ?? $globalDefault ?? false);
        }

        $queueName = $options['queue']
            ?? config("manager-core.events.handler_defaults.{$resolvedPlugin}.queue")
            ?? config('manager-core.events.handler_defaults.default.queue');

        $entry = [
            'class' => $handlerClass,
            'queued' => $queued,
            'queue' => $queueName,
            'plugin' => $resolvedPlugin,
        ];

        foreach ($types as $type) {
            if (!isset($this->handlers[$type])) {
                $this->handlers[$type] = [];
            }
            // Dedupe by handler class
            $exists = false;
            foreach ($this->handlers[$type] as $existing) {
                if ($existing['class'] === $handlerClass) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $this->handlers[$type][] = $entry;
            }
        }

        $this->registrations[] = [
            'plugin' => $resolvedPlugin,
            'types' => $types,
            'handler' => $handlerClass,
            'queued' => $queued,
        ];

        Log::debug(
            'EsiNotificationRegistry: registered ' . count($types) . ' type(s) for ' . $handlerClass . ($queued ? ' [queued]' : ' [sync]'),
            ['types' => $types]
        );
    }

    /**
     * Get all handler class names registered for a given notification type.
     * (Backward-compat — returns just the class names, not the full entries.)
     *
     * @return array<int, string>
     */
    public function getHandlersForType(string $type): array
    {
        return array_map(fn($e) => $e['class'], $this->handlers[$type] ?? []);
    }

    /**
     * Get full handler entries (class + queued flag + queue name + plugin).
     *
     * @return array<int, array>
     */
    public function getHandlerEntriesForType(string $type): array
    {
        return $this->handlers[$type] ?? [];
    }

    /**
     * Get all types that have at least one handler registered.
     *
     * @return array<int, string>
     */
    public function getRegisteredTypes(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Check if any plugin has registered for the given type.
     */
    public function hasHandlersForType(string $type): bool
    {
        return !empty($this->handlers[$type] ?? []);
    }

    /**
     * Get all registrations grouped by plugin — for the diagnostic UI.
     * Includes per-handler latency stats so operators can spot slow subscribers.
     *
     * @return array<string, array{types: array, handlers: array, stats: array}>
     */
    public function getRegistrationsByPlugin(): array
    {
        $grouped = [];

        foreach ($this->registrations as $reg) {
            $plugin = $reg['plugin'];
            if (!isset($grouped[$plugin])) {
                $grouped[$plugin] = ['types' => [], 'handlers' => [], 'stats' => []];
            }
            $grouped[$plugin]['types'] = array_unique(array_merge($grouped[$plugin]['types'], $reg['types']));
            if (!in_array($reg['handler'], $grouped[$plugin]['handlers'], true)) {
                $grouped[$plugin]['handlers'][] = $reg['handler'];
            }
            // Attach latency stats for this handler
            if (isset($this->handlerStats[$reg['handler']])) {
                $stats = $this->handlerStats[$reg['handler']];
                $grouped[$plugin]['stats'][$reg['handler']] = [
                    'invocations' => $stats['count'],
                    'avg_ms' => $stats['count'] > 0 ? (int) ($stats['total_ms'] / $stats['count']) : 0,
                    'max_ms' => $stats['max_ms'],
                    'slow_count' => $stats['slow_count'],
                    'queued' => $reg['queued'],
                ];
            }
        }

        return $grouped;
    }

    /**
     * Dispatch a notification to every registered handler for its type.
     *
     * H7: Handlers registered with 'queued' => true are dispatched as queued
     * jobs (DispatchEsiNotificationJob), so MC's poll job doesn't wait for
     * their HTTP work. Sync handlers run inline and have their wall-clock
     * tracked — slow ones produce a warning log.
     *
     * Returns the number of handlers invoked (sync) or queued (async).
     * Errors in individual handlers are caught and logged.
     */
    public function dispatch($notification): int
    {
        $type = $notification->type ?? null;
        if (!$type) {
            return 0;
        }

        $entries = $this->getHandlerEntriesForType($type);
        if (empty($entries)) {
            return 0;
        }

        $invoked = 0;
        foreach ($entries as $entry) {
            $handlerClass = $entry['class'];

            try {
                if (!class_exists($handlerClass)) {
                    Log::warning("EsiNotificationRegistry: handler class {$handlerClass} does not exist (plugin uninstalled?)");
                    continue;
                }

                if ($entry['queued']) {
                    // Queued path — dispatch a job and return immediately
                    $job = new \ManagerCore\Jobs\ESI\DispatchEsiNotificationJob(
                        $handlerClass,
                        (int) ($notification->id ?? $notification->notification_id ?? 0)
                    );

                    if (!empty($entry['queue'])) {
                        $job->onQueue($entry['queue']);
                    }

                    dispatch($job);
                    $invoked++;
                    continue;
                }

                // Sync path — invoke inline and track timing
                if (!method_exists($handlerClass, 'handle')) {
                    Log::warning("EsiNotificationRegistry: handler {$handlerClass} has no handle() method");
                    continue;
                }

                $start = microtime(true);
                $handlerClass::handle($notification);
                $elapsedMs = (int) ((microtime(true) - $start) * 1000);

                $this->recordHandlerStat($handlerClass, $elapsedMs);

                if ($elapsedMs >= self::SLOW_HANDLER_MS) {
                    Log::warning("EsiNotificationRegistry: SLOW sync handler {$handlerClass} took {$elapsedMs}ms — consider queued=true", [
                        'notification_id' => $notification->notification_id ?? null,
                        'type' => $type,
                    ]);
                }

                $invoked++;
            } catch (\Throwable $e) {
                Log::error(
                    "EsiNotificationRegistry: handler {$handlerClass} failed for notification #" . ($notification->notification_id ?? 'unknown') . ': ' . $e->getMessage()
                );
            }
        }

        return $invoked;
    }

    /**
     * Track a handler's invocation timing for the diagnostic UI.
     */
    protected function recordHandlerStat(string $handlerClass, int $elapsedMs): void
    {
        if (!isset($this->handlerStats[$handlerClass])) {
            $this->handlerStats[$handlerClass] = ['count' => 0, 'total_ms' => 0, 'max_ms' => 0, 'slow_count' => 0];
        }
        $this->handlerStats[$handlerClass]['count']++;
        $this->handlerStats[$handlerClass]['total_ms'] += $elapsedMs;
        if ($elapsedMs > $this->handlerStats[$handlerClass]['max_ms']) {
            $this->handlerStats[$handlerClass]['max_ms'] = $elapsedMs;
        }
        if ($elapsedMs >= self::SLOW_HANDLER_MS) {
            $this->handlerStats[$handlerClass]['slow_count']++;
        }
    }

    /**
     * Infer a plugin name from a fully-qualified handler class.
     * e.g. \StructureManager\Handlers\StructureEventHandler -> "structure-manager"
     */
    protected function inferPluginName(string $handlerClass): string
    {
        $parts = explode('\\', ltrim($handlerClass, '\\'));
        $root = $parts[0] ?? 'unknown';
        // Convert CamelCase to kebab-case
        $kebab = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $root));
        return $kebab;
    }
}
