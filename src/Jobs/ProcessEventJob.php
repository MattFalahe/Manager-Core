<?php

namespace ManagerCore\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\EventSubscription;
use ManagerCore\Services\PluginBridge;

/**
 * Queued job for async event handling
 *
 * Dispatched by EventBus when a subscription has is_queued = true
 *
 * L10 fix: snapshots the subscription's dispatch fields at construction time
 *          so an in-flight job uses the SAME handler that was matched at
 *          publish-time, not whatever the row currently holds.
 *
 * L11 fix: honors per-subscription timeout_seconds (with a hard 600s cap to
 *          stay well under SeAT's 960s queue retry_after).
 */
class ProcessEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $maxExceptions = 2;
    public array $backoff = [30, 120, 300];

    /**
     * Effective timeout. Defaults to 60s; overridable per subscription.
     */
    public int $timeout = 60;

    /**
     * L10: snapshot of the subscription's dispatch fields at the moment of
     * publish. Persisted so reschedule / retry uses the same handler config
     * that was originally matched.
     */
    public ?string $snapshotHandlerClass;
    public ?string $snapshotHandlerMethod;
    public ?string $snapshotHandlerCapability;
    public string $snapshotSubscriberPlugin;

    public function __construct(
        public string $eventName,
        public string $publisherPlugin,
        public array $payload,
        public int $subscriptionId,
        ?EventSubscription $subscription = null
    ) {
        // L10: capture snapshot from the subscription instance so in-flight
        // jobs aren't affected by mid-flight subscription edits.
        if ($subscription) {
            $this->snapshotHandlerClass = $subscription->handler_class;
            $this->snapshotHandlerMethod = $subscription->handler_method;
            $this->snapshotHandlerCapability = $subscription->handler_capability;
            $this->snapshotSubscriberPlugin = $subscription->subscriber_plugin;

            // L11: honor per-subscription timeout_seconds, capped to 600s
            $perSub = (int) ($subscription->timeout_seconds ?? 0);
            if ($perSub > 0) {
                $this->timeout = min($perSub, 600);
            }
        } else {
            // Caller didn't pass a snapshot — fall back to runtime fetch
            $this->snapshotHandlerClass = null;
            $this->snapshotHandlerMethod = null;
            $this->snapshotHandlerCapability = null;
            $this->snapshotSubscriberPlugin = '';
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // L10: rely on the snapshot taken at dispatch time when present.
        // Fall back to a fresh fetch only when the snapshot is unavailable
        // (e.g. job was constructed by older code before this field existed).
        if ($this->snapshotSubscriberPlugin === '') {
            $subscription = EventSubscription::find($this->subscriptionId);

            // L2: distinguish "deleted" from "deactivated" so operators can
            // tell whether to investigate or accept the silence.
            if (!$subscription) {
                Log::info("[Manager Core] Event subscription #{$this->subscriptionId} was DELETED before async dispatch — skipping");
                return;
            }

            if (!$subscription->is_active) {
                Log::info("[Manager Core] Event subscription #{$this->subscriptionId} was DEACTIVATED before async dispatch — skipping");
                return;
            }

            $this->snapshotHandlerClass = $subscription->handler_class;
            $this->snapshotHandlerMethod = $subscription->handler_method;
            $this->snapshotHandlerCapability = $subscription->handler_capability;
            $this->snapshotSubscriberPlugin = $subscription->subscriber_plugin;
        }

        // Best-effort active-flag check (cheap WHERE on indexed PK) — gives
        // operators a way to disable an in-flight queue of jobs by toggling
        // is_active=false on the subscription.
        try {
            $stillActive = EventSubscription::where('id', $this->subscriptionId)
                ->where('is_active', true)
                ->exists();
            if (!$stillActive) {
                Log::info("[Manager Core] Event subscription #{$this->subscriptionId} no longer active — skipping queued dispatch");
                return;
            }
        } catch (\Throwable $e) {
            // DB hiccup — proceed with the snapshot rather than block dispatch
        }

        try {
            if ($this->snapshotHandlerClass) {
                // Class-based handler — invoke against the snapshot
                if (!class_exists($this->snapshotHandlerClass)) {
                    Log::warning("[Manager Core] Snapshot handler class {$this->snapshotHandlerClass} no longer exists — skipping");
                    return;
                }

                $handler = app($this->snapshotHandlerClass);
                $method = $this->snapshotHandlerMethod ?? 'handle';
                $handler->$method($this->eventName, $this->publisherPlugin, $this->payload);
            } else {
                // PluginBridge capability handler
                $bridge = app(PluginBridge::class);

                if ($bridge->hasCapability($this->snapshotSubscriberPlugin, $this->snapshotHandlerCapability)) {
                    $bridge->call(
                        $this->snapshotSubscriberPlugin,
                        $this->snapshotHandlerCapability,
                        $this->eventName,
                        $this->publisherPlugin,
                        $this->payload
                    );
                } else {
                    Log::warning("[Manager Core] Capability '{$this->snapshotHandlerCapability}' not found for plugin '{$this->snapshotSubscriberPlugin}'");
                }
            }
        } catch (\Throwable $e) {
            Log::error("[Manager Core] Async event handler failed", [
                'event' => $this->eventName,
                'subscriber' => $this->snapshotSubscriberPlugin,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
