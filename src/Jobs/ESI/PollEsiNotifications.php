<?php

namespace ManagerCore\Jobs\ESI;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\ESI\EsiKeyHolder;
use ManagerCore\Models\ESI\EsiNotification;
use ManagerCore\Models\WorkerRegistrySnapshot;
use ManagerCore\Services\ESI\EsiNotificationRegistry;
use Seat\Services\Contracts\EsiClient;
use Seat\Eveapi\Models\RefreshToken;
use Carbon\Carbon;
use Symfony\Component\Yaml\Yaml;

/**
 * Shared ESI notification polling job (Manager Core).
 *
 * Bypasses SeAT's 20-30 minute bucket delay by polling the ESI notifications
 * endpoint directly from admin-assigned director characters in a round-robin
 * pattern. Serves every plugin that registers handlers via EsiNotificationRegistry.
 *
 * With 10 directors in the pool polled 2 at a time every 2 minutes, detection
 * time drops to ~2 minutes — a 10-15x speed improvement over SeAT's default.
 *
 * Flow:
 * 1. Get next 2 key holders from the round-robin pool
 * 2. Call ESI via Eseye for each
 * 3. Dedup against manager_core_esi_notifications by CCP's notification_id
 * 4. For new notifications: route through EsiNotificationRegistry to subscribed plugins
 * 5. Mark dispatched in the shared table
 */
class PollEsiNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 300;
    public $tries = 3;
    public $backoff = [60, 300, 900];

    /**
     * Skip notifications older than this many hours — stale notifications
     * have already been handled (or aren't actionable anymore).
     */
    const STALE_HOURS = 2;

    /**
     * Adaptive-batch sizing constants. The formula targets one poll per
     * character per cache-TTL window: batch = ceil(pool_size * schedule / cache).
     */
    const SCHEDULE_INTERVAL_SECONDS = 120;        // matches the */2 * * * * cron
    const TARGET_PER_CHAR_INTERVAL_SECONDS = 600; // matches CCP's notifications cache TTL
    const MIN_BATCH = 1;
    const MAX_BATCH = 30;                         // safety cap so a giant pool
                                                  // can't blow out the 300s job timeout

    /**
     * Cascade-retry tunables. When an entire batch fails (e.g. CCP 5xx burst),
     * self-dispatch with a small delay so transient blips don't force a wait
     * for the next 2-minute cron tick. Capped to prevent infinite chains.
     */
    const MAX_CASCADE_DEPTH = 3;     // tries up to 4 batches in ~15 seconds
    const CASCADE_DELAY_SECONDS = 5;

    /**
     * Cascade-chain state. Public so they survive serialization into the
     * queue.
     *
     * cascadeDepth: 0 = first attempt of this 2-min cycle. Bumps for each
     *               self-dispatch.
     * cascadeExcludedIds: characters already tried in this cascade chain.
     *                    Prevents re-polling the same failed chars.
     */
    public int $cascadeDepth = 0;
    public array $cascadeExcludedIds = [];

    /**
     * Execute the job.
     */
    public function handle()
    {
        $tag = $this->cascadeDepth > 0 ? " [cascade depth={$this->cascadeDepth}]" : '';
        Log::info("PollEsiNotifications: Job started{$tag}");

        $registry = app(EsiNotificationRegistry::class);

        // Snapshot the worker's in-memory registry state at job start. Even
        // if we exit early below (empty registry, empty pool), we still
        // record what the worker saw so the bridge dashboard surfaces the
        // problem. The 2026-05-11 session burned hours hunting this exact
        // state-invisibility issue — now operators see it directly.
        $registeredTypes = $registry->getRegisteredTypes();
        $totalKeyHolders = EsiKeyHolder::where('enabled', true)->count();
        $this->writeSnapshot($registry, count($registeredTypes), $totalKeyHolders);

        // If no plugins have registered handlers, there's no work to do
        if (empty($registeredTypes)) {
            Log::debug('PollEsiNotifications: No plugins have registered notification handlers; skipping poll');
            return;
        }

        // Adaptive batch sizing — recomputed each run so pool growth /
        // shrinkage takes effect immediately. See computeBatchSize() for
        // the formula.
        $batchSize = $this->computeBatchSize();

        // Get next key holders from the shared pool using per-corp fair
        // LRU rotation. Pass cascadeExcludedIds so a cascade chain tries
        // DIFFERENT chars than the previous batch.
        $keyHolders = EsiKeyHolder::getNextInRotation($batchSize, $this->cascadeExcludedIds);

        if ($keyHolders->isEmpty()) {
            Log::debug('PollEsiNotifications: No enabled key holders to poll (add directors or all suspended)');
            return;
        }

        $corpCount = $keyHolders->pluck('corporation_id')->unique()->count();
        Log::info("PollEsiNotifications: Polling {$keyHolders->count()} key holder(s) across {$corpCount} corp(s); {$tag}batch_size={$batchSize}, " . count($registeredTypes) . ' type(s) registered');

        $newCount = 0;
        $successCount = 0;
        $failCount = 0;

        foreach ($keyHolders as $keyHolder) {
            try {
                $newCount += $this->pollKeyHolder($keyHolder, $registeredTypes);
                $successCount++;
            } catch (\Throwable $e) {
                $failCount++;
                [$category, $status] = self::categorizeFailure($e);
                $keyHolder->recordFailure($status, $e->getMessage(), $category);
                Log::warning("PollEsiNotifications: Failed to poll key holder {$keyHolder->character_id} (cat={$category}): " . $e->getMessage());
            }
        }

        // Dispatch undispatched notifications to registered handlers
        $dispatchedCount = $this->dispatchUndispatched($registry);

        Log::info("PollEsiNotifications: Done. New: {$newCount}, Dispatched: {$dispatchedCount}, Success: {$successCount}, Failed: {$failCount}");

        // CASCADE RETRY: if every char in this batch failed AND there are
        // more eligible chars not yet tried in this cascade chain, dispatch
        // another batch with a small delay. Transient CCP burst (5xx) gets
        // covered within ~15 seconds instead of waiting 2 minutes.
        if ($failCount > 0 && $successCount === 0 && $this->cascadeDepth < self::MAX_CASCADE_DEPTH) {
            $triedIds = array_merge(
                $this->cascadeExcludedIds,
                $keyHolders->pluck('id')->toArray()
            );

            $remaining = EsiKeyHolder::where('enabled', true)
                ->where(function ($q) {
                    $q->whereNull('suspended_until')
                      ->orWhere('suspended_until', '<=', \Carbon\Carbon::now());
                })
                ->whereNotIn('id', $triedIds)
                ->exists();

            if ($remaining) {
                $next = new self();
                $next->cascadeDepth = $this->cascadeDepth + 1;
                $next->cascadeExcludedIds = $triedIds;
                dispatch($next)->delay(\Carbon\Carbon::now()->addSeconds(self::CASCADE_DELAY_SECONDS));
                Log::info("PollEsiNotifications: All {$failCount} chars failed; cascading to next batch (depth {$next->cascadeDepth}) in " . self::CASCADE_DELAY_SECONDS . 's');
            } else {
                Log::info("PollEsiNotifications: All {$failCount} chars failed; no remaining eligible chars in pool, will wait for next cron tick");
            }
        }
    }

    /**
     * Compute the per-cycle batch size for the current state of the pool.
     *
     * Instance shorthand for the static formula — the controller calls the
     * static version directly when rendering the admin UI banner so the
     * displayed values match what the next poll will actually use.
     */
    private function computeBatchSize(): int
    {
        return self::computeBatchSizeForPool(
            EsiKeyHolder::countEligible(),
            EsiKeyHolder::countEligibleCorps()
        );
    }

    /**
     * Pure batch-size formula for a given pool/corp count.
     *
     * Strategy: each character should be polled once per cache window
     * (~10 min, matching CCP's notifications endpoint cache TTL). Combined
     * with a per-corp fair rotation (1 char per corp per cycle), this means
     * the batch needs to cover every corp in a single cycle for the "2-min
     * detection per corp" promise.
     *
     * Formula: max(corps_count, ceil(pool_count * schedule / cache))
     *   - corps_count keeps each corp covered in every cycle when possible
     *   - the per-pool calculation handles single-corp megapools where the
     *     rotation needs more chars per cycle to give every director a turn
     *     within the cache TTL
     *
     * Clamped to [MIN_BATCH, MAX_BATCH]. Past MAX_BATCH (30), the cycle
     * gracefully degrades — corps wait longer for their turn but the job
     * timeout (300s) and worker capacity stay safe.
     *
     * Public so the admin UI can show the same numbers the next poll will
     * use without duplicating the formula.
     */
    public static function computeBatchSizeForPool(int $eligibleCount, int $corpsCount): int
    {
        if ($eligibleCount === 0) {
            return self::MIN_BATCH;
        }

        $byPool = (int) ceil($eligibleCount * self::SCHEDULE_INTERVAL_SECONDS / self::TARGET_PER_CHAR_INTERVAL_SECONDS);
        $batch = max($byPool, $corpsCount);

        return max(self::MIN_BATCH, min(self::MAX_BATCH, $batch));
    }

    /**
     * Estimate the effective per-corp poll cadence (in minutes) given the
     * current pool. Used by the admin UI banner to show "each corp polled
     * every ~N minutes" — operator-friendly translation of the math.
     *
     * Returns null when the pool is empty.
     */
    public static function estimatePerCorpCadenceMinutes(int $eligibleCount, int $corpsCount): ?float
    {
        if ($eligibleCount === 0 || $corpsCount === 0) {
            return null;
        }
        $batch = self::computeBatchSizeForPool($eligibleCount, $corpsCount);
        // Cycles required to cover every corp once (batch covers up to
        // batch corps per cycle)
        $cycles = (int) ceil($corpsCount / $batch);
        return ($cycles * self::SCHEDULE_INTERVAL_SECONDS) / 60.0;
    }

    /**
     * Poll a single key holder's notifications from ESI.
     *
     * @return int Number of new notifications found
     */
    private function pollKeyHolder(EsiKeyHolder $keyHolder, array $registeredTypes): int
    {
        $characterId = $keyHolder->character_id;
        $corporationId = $keyHolder->corporation_id;

        // Get the refresh token. Missing row = SeAT deleted it (revoked,
        // PermanentInvalidTokenException, never linked). Categorize as
        // terminal_auth so the cooldown ladder is 7d, not 30m exponential.
        $token = RefreshToken::find($characterId);
        if (!$token) {
            $keyHolder->recordFailure(
                'token_expired',
                'No refresh token found in SeAT',
                EsiKeyHolder::CATEGORY_TERMINAL_AUTH
            );
            return 0;
        }

        // Check scope. Token exists but doesn't have the required scope —
        // operator needs to re-link with the scope ticked. Same long
        // cooldown as terminal_auth because nothing auto-resolves this.
        $scopes = $token->scopes ?? [];
        if (is_string($scopes)) {
            $scopes = json_decode($scopes, true) ?? [];
        }
        if (!in_array('esi-characters.read_notifications.v1', $scopes)) {
            $keyHolder->recordFailure(
                'scope_missing',
                'Missing esi-characters.read_notifications.v1 scope',
                EsiKeyHolder::CATEGORY_SCOPE_MISSING
            );
            return 0;
        }

        // Call ESI via Eseye (SeAT's HTTP client wrapper — handles auth, rate limits)
        $esi = app()->make(EsiClient::class);
        $esi->setAuthentication($token);

        $response = $esi->invoke('get', '/characters/{character_id}/notifications/', [
            'character_id' => $characterId,
        ]);

        // Update refresh token after ESI call (Eseye may have refreshed it)
        try {
            $updatedAuth = $esi->getAuthentication();
            $token->token = $updatedAuth->getAccessToken();
            $token->refresh_token = $updatedAuth->getRefreshToken();
            $token->expires_on = $updatedAuth->getExpiresOn();
            $token->save();
        } catch (\Throwable $e) {
            Log::debug("PollEsiNotifications: Could not update token for {$characterId}: " . $e->getMessage());
        }

        $notifications = $response->getBody();
        if (!is_array($notifications) && !($notifications instanceof \Traversable)) {
            $notifications = [];
        }

        $newCount = 0;

        foreach ($notifications as $notification) {
            $type = $notification->type ?? null;
            $notificationId = $notification->notification_id ?? null;

            if (!$type || !$notificationId) {
                continue;
            }

            // Filter: only types that at least one plugin is listening for.
            // Saves DB writes — we don't need to store notifications nobody cares about.
            if (!in_array($type, $registeredTypes, true)) {
                continue;
            }

            // Skip stale notifications
            $timestamp = Carbon::parse($notification->timestamp ?? 'now');
            if ($timestamp->lt(Carbon::now()->subHours(self::STALE_HOURS))) {
                continue;
            }

            // Dedup check
            if (EsiNotification::notificationExists((int) $notificationId)) {
                continue;
            }

            // Parse YAML text
            $rawText = $notification->text ?? '';
            $parsedData = null;
            try {
                $parsedData = Yaml::parse($rawText);
            } catch (\Throwable $e) {
                Log::debug("PollEsiNotifications: YAML parse failed for notification {$notificationId}: " . $e->getMessage());
                $parsedData = ['raw' => $rawText];
            }

            // Insert. Wrap in try/catch for duplicate key race (sweep may have beaten us to it).
            try {
                EsiNotification::create([
                    'notification_id' => $notificationId,
                    'character_id' => $characterId,
                    'corporation_id' => $corporationId,
                    'type' => $type,
                    'sender_id' => $notification->sender_id ?? null,
                    'sender_type' => $notification->sender_type ?? null,
                    'timestamp' => $timestamp,
                    'text' => $rawText,
                    'parsed_data' => $parsedData,
                    'source' => 'fast_poll',
                    'dispatched' => false,
                ]);
                $newCount++;
                Log::info("PollEsiNotifications: New {$type} #{$notificationId} from key holder {$keyHolder->character_name}");
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE constraint')) {
                    continue;
                }
                throw $e;
            }
        }

        $keyHolder->recordSuccess($newCount);

        return $newCount;
    }

    /**
     * Dispatch undispatched notifications to registered handlers.
     * Uses DB transaction with lockForUpdate to prevent concurrent jobs from
     * double-dispatching the same notifications.
     */
    private function dispatchUndispatched(EsiNotificationRegistry $registry): int
    {
        $dispatched = 0;

        DB::transaction(function () use (&$dispatched, $registry) {
            $undispatched = EsiNotification::where('dispatched', false)
                ->orderBy('timestamp', 'asc')
                ->limit(50)
                ->lockForUpdate()
                ->get();

            if ($undispatched->isEmpty()) {
                return;
            }

            foreach ($undispatched as $notification) {
                try {
                    $registry->dispatch($notification);
                    $notification->markDispatched();
                    $dispatched++;
                } catch (\Throwable $e) {
                    Log::error("PollEsiNotifications: Failed to dispatch notification #{$notification->notification_id}: " . $e->getMessage());
                }
            }
        });

        return $dispatched;
    }

    /**
     * Classify an exception thrown by the ESI call into a category +
     * status pair that drives the cooldown ladder in EsiKeyHolder.
     *
     * We can't rely on getting a proper Eseye exception class because
     * different SeAT versions wrap differently — some throw
     * RequestFailedException with HTTP status in the message, some throw
     * PermanentInvalidTokenException, some bubble up raw Guzzle exceptions.
     * Pattern-matching the message + class chain is the resilient option.
     *
     * Returns [category, status_short_code]. The status code lands in
     * the model's last_poll_status column for human-readable display,
     * the category drives the cooldown calculator.
     */
    public static function categorizeFailure(\Throwable $e): array
    {
        $msg = $e->getMessage();
        $msgLower = strtolower($msg);

        // SeAT marks tokens it considers permanently dead via this exception.
        // Class names have varied between major versions — match the
        // substring 'PermanentInvalidToken' against the full class chain.
        $classChain = get_class($e);
        $cause = $e->getPrevious();
        while ($cause instanceof \Throwable) {
            $classChain .= '|' . get_class($cause);
            $cause = $cause->getPrevious();
        }
        if (str_contains($classChain, 'PermanentInvalidToken')) {
            return [EsiKeyHolder::CATEGORY_TERMINAL_AUTH, 'token_invalid_permanent'];
        }

        // 420 error-limited. Aggressive cooldown — we don't want to
        // contribute to further error-budget depletion across the app.
        if (str_contains($msg, '420') || str_contains($msgLower, 'error limited')) {
            return [EsiKeyHolder::CATEGORY_RATE_LIMITED, 'rate_limited'];
        }

        // 401 unauthorized / invalid_token. Most common transient failure —
        // SeAT's background token-refresh job may make this go away on the
        // next poll cycle, so cooldown is short.
        if (str_contains($msg, '401')
            || str_contains($msgLower, 'invalid_token')
            || str_contains($msgLower, 'invalid token')
            || str_contains($msgLower, 'unauthorized')) {
            return [EsiKeyHolder::CATEGORY_TRANSIENT_AUTH, 'token_invalid_transient'];
        }

        // 403 forbidden — scope mismatch or character lost access.
        // Treated as scope_missing since both require operator action.
        if (str_contains($msg, '403') || str_contains($msgLower, 'forbidden')) {
            return [EsiKeyHolder::CATEGORY_SCOPE_MISSING, 'forbidden'];
        }

        // Timeouts and 5xx — CCP infrastructure transient.
        // Tighter exponential ladder than auth issues (10m start vs 30m).
        if (str_contains($msgLower, 'timeout')
            || str_contains($msgLower, 'tranquility')
            || str_contains($msgLower, 'connection')
            || preg_match('/\b5\d{2}\b/', $msg)) {
            return [EsiKeyHolder::CATEGORY_NETWORK, 'network_error'];
        }

        return [EsiKeyHolder::CATEGORY_UNKNOWN, 'failed'];
    }

    /**
     * Persist a snapshot of the in-memory registry state at job start so
     * the Plugin Bridge dashboard can show operators what the worker
     * actually sees.
     *
     * Wrapped in try/catch — snapshot logging must never block the actual
     * polling work. A snapshot table that doesn't exist (older MC install
     * pre-migration) is silently tolerated.
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
            Log::debug('PollEsiNotifications: snapshot write failed (non-fatal): ' . $e->getMessage());
        }
    }
}
