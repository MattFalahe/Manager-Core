<?php

namespace ManagerCore\Jobs\ESI;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ManagerCore\Models\ESI\EsiKeyHolder;
use ManagerCore\Models\ESI\EsiNotification;
use ManagerCore\Models\WorkerRegistrySnapshot;
use ManagerCore\Services\ESI\EsiNotificationRegistry;
use Carbon\Carbon;
use Symfony\Component\Yaml\Yaml;

/**
 * Fallback sweep of SeAT's character_notifications table.
 *
 * Runs every 10 minutes. Picks up any notifications that the fast-poll missed
 * (all key holders had expired tokens, ESI was down during our poll window,
 * etc.) or that arrived for notification types registered AFTER fast-poll
 * last ran. Deduplicates by notification_id against the shared MC table.
 *
 * This is the "belt-and-suspenders" layer — fast-poll handles 90%+ of
 * notifications within 2 minutes; this sweep catches the rest within ~10-20
 * minutes (still faster than SeAT's 20-30 min bucket delay because we process
 * as soon as SeAT writes to its own table).
 */
class SweepSeatNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 120;
    public $tries = 2;
    public $backoff = [30, 60];

    /**
     * Execute the job.
     */
    public function handle()
    {
        if (!Schema::hasTable('character_notifications')) {
            Log::debug('SweepSeatNotifications: character_notifications table not found (SeAT incomplete install?)');
            return;
        }

        $registry = app(EsiNotificationRegistry::class);
        $registeredTypes = $registry->getRegisteredTypes();

        // Snapshot the worker's registry state for the dashboard. Same
        // rationale as the matching call in PollEsiNotifications: the
        // in-memory registry is per-process, so the only way an HTTP
        // context can see what the worker sees is via a persisted row.
        $totalKeyHolders = EsiKeyHolder::where('enabled', true)->count();
        $this->writeSnapshot($registry, count($registeredTypes), $totalKeyHolders);

        if (empty($registeredTypes)) {
            Log::debug('SweepSeatNotifications: No plugins have registered handlers; skipping sweep');
            return;
        }

        // Look back 2 hours — anything older is probably already processed or stale
        $cutoff = Carbon::now()->subHours(2);

        $seatNotifications = DB::table('character_notifications')
            ->whereIn('type', $registeredTypes)
            ->where('timestamp', '>=', $cutoff)
            ->orderBy('timestamp', 'desc')
            ->limit(200)
            ->get();

        if ($seatNotifications->isEmpty()) {
            Log::debug('SweepSeatNotifications: No relevant notifications in SeAT table within 2h window');
            return;
        }

        Log::info("SweepSeatNotifications: Found {$seatNotifications->count()} candidate notification(s) in SeAT table");

        $newCount = 0;

        foreach ($seatNotifications as $seatNotif) {
            $notificationId = $seatNotif->notification_id;

            // Dedup against the shared MC table
            if (EsiNotification::notificationExists((int) $notificationId)) {
                continue;
            }

            // Resolve corporation_id from character_affiliations
            $corporationId = DB::table('character_affiliations')
                ->where('character_id', $seatNotif->character_id)
                ->value('corporation_id') ?? 0;

            // Parse the YAML text
            $rawText = $seatNotif->text ?? '';
            $parsedData = null;
            try {
                $parsedData = is_string($rawText) ? Yaml::parse($rawText) : $rawText;
            } catch (\Throwable $e) {
                $parsedData = ['raw' => $rawText];
            }

            // Insert as fallback source. Catch duplicate key races.
            try {
                EsiNotification::create([
                    'notification_id' => $notificationId,
                    'character_id' => $seatNotif->character_id,
                    'corporation_id' => $corporationId,
                    'type' => $seatNotif->type,
                    'sender_id' => $seatNotif->sender_id ?? null,
                    'sender_type' => $seatNotif->sender_type ?? null,
                    'timestamp' => $seatNotif->timestamp,
                    'text' => is_string($rawText) ? $rawText : json_encode($rawText),
                    'parsed_data' => $parsedData,
                    'source' => 'seat_fallback',
                    'dispatched' => false,
                ]);

                $newCount++;
                Log::info("SweepSeatNotifications: Picked up {$seatNotif->type} #{$notificationId} (fallback)");
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE constraint')) {
                    continue;
                }
                throw $e;
            }
        }

        Log::info("SweepSeatNotifications: Done. New fallback notifications: {$newCount}");

        // NOTE: undispatched notifications from the sweep will be picked up by the next
        // PollEsiNotifications cycle (every 2 min), which handles dispatching to registered
        // handlers with proper DB locking. We don't dispatch here to avoid concurrency issues.
    }

    /**
     * Persist a snapshot of the in-memory registry state at job start so
     * the Plugin Bridge dashboard can show operators what the worker
     * actually sees. Same pattern as PollEsiNotifications::writeSnapshot.
     */
    private function writeSnapshot(EsiNotificationRegistry $registry, int $handlersCount, int $keyPoolSize): void
    {
        try {
            $byPlugin = $registry->getRegistrationsByPlugin();
            $pluginsSeen = array_keys($byPlugin);

            WorkerRegistrySnapshot::create([
                'job_class' => static::class,
                'handlers_count' => $handlersCount,
                'types_count' => count($registry->getRegisteredTypes()),
                'plugins_seen' => $pluginsSeen,
                'key_pool_size' => $keyPoolSize,
                'outcome' => null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('SweepSeatNotifications: snapshot write failed (non-fatal): ' . $e->getMessage());
        }
    }
}
