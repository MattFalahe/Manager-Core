<?php

namespace ManagerCore\Jobs\ESI;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that invokes a single ESI notification handler asynchronously.
 *
 * H7 fix: Allows EsiNotificationRegistry handlers (especially those that
 * make HTTP calls to webhooks) to opt out of the synchronous dispatch path
 * inside MC's PollEsiNotifications job, decoupling MC's job timeout budget
 * from subscriber webhook latency.
 */
class DispatchEsiNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes
    public int $maxExceptions = 2;
    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $handlerClass,
        public int $notificationId
    ) {}

    public function handle(): void
    {
        if (!class_exists($this->handlerClass)) {
            Log::warning("[Manager Core] Queued ESI handler class {$this->handlerClass} does not exist — skipping");
            return;
        }

        if (!method_exists($this->handlerClass, 'handle')) {
            Log::warning("[Manager Core] Queued ESI handler {$this->handlerClass} has no handle() method");
            return;
        }

        $notificationModel = '\ManagerCore\Models\ESI\EsiNotification';
        if (!class_exists($notificationModel)) {
            Log::warning('[Manager Core] EsiNotification model class not found — skipping');
            return;
        }

        $notification = $notificationModel::find($this->notificationId);
        if (!$notification) {
            Log::warning("[Manager Core] ESI notification #{$this->notificationId} not found (deleted?) — skipping");
            return;
        }

        $start = microtime(true);
        try {
            $this->handlerClass::handle($notification);
        } catch (\Throwable $e) {
            Log::error(
                "[Manager Core] Queued ESI handler {$this->handlerClass} failed for notification #{$this->notificationId}: " . $e->getMessage()
            );
            throw $e;
        } finally {
            $elapsedMs = (int) ((microtime(true) - $start) * 1000);
            Log::debug("[Manager Core] Queued ESI handler {$this->handlerClass} processed notification #{$this->notificationId} in {$elapsedMs}ms");
        }
    }
}
