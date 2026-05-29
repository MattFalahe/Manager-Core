<?php

namespace ManagerCore\Services\Watchdog\Checks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ManagerCore\Services\Watchdog\WatchdogCheck;

/**
 * Alert when MC's ESI fast-poll is failing across the key pool.
 *
 * Failure modes this catches:
 *   - CCP ESI outage (5xx everywhere)
 *   - Every director's refresh_token expired simultaneously (rare —
 *     suggests SeAT eveapi config issue)
 *   - Pool exhausted (every key suspended after auto-recovery cooldown)
 *   - All keys missing the esi-characters.read_notifications scope
 *
 * Threshold: of the N enabled key holders, ≥80% have their most recent
 * poll status set to anything other than 'success'. Tuned to NOT fire
 * when 1-2 keys are misbehaving (operator can fix individually); fires
 * when the whole pool is sick (systemic problem).
 *
 * Excludes the normal "downtime + auto-recovery cooldown" pattern via
 * the WatchdogService-level exclusion window (Matt: 11:00-11:10 UTC).
 */
class EsiFastPollFailingCheck implements WatchdogCheck
{
    /** Percent of pool that must be failing to alert. 0.80 = 80%. */
    private const FAILURE_RATIO_THRESHOLD = 0.80;

    /** Minimum pool size for this check to even run. Below this the
     *  ratio is noisy (1 of 2 keys failing = 50% which isn't actually
     *  systemic). Defer single-key issues to the operator. */
    private const MIN_POOL_SIZE = 3;

    public function name(): string { return 'esi_fast_poll_failing'; }
    public function label(): string { return 'ESI fast-poll failing'; }
    public function description(): string
    {
        return 'Alerts when ≥' . (int)(self::FAILURE_RATIO_THRESHOLD * 100) . '% of enabled ESI key holders have a non-success last_poll_status. Indicates CCP ESI outage, all-keys-expired, or pool exhaustion. Skips when pool has fewer than ' . self::MIN_POOL_SIZE . ' enabled keys.';
    }

    public function run(): ?array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('manager_core_esi_key_holders')) {
                return null;
            }

            $rows = DB::table('manager_core_esi_key_holders')
                ->where('enabled', true)
                ->whereNotNull('last_polled_at')
                ->get(['character_id', 'character_name', 'last_poll_status', 'failure_category', 'last_error', 'consecutive_failures']);

            $total = $rows->count();
            if ($total < self::MIN_POOL_SIZE) {
                return null;
            }

            $failing = $rows->filter(function ($r) {
                return $r->last_poll_status !== null && $r->last_poll_status !== 'success';
            });

            $failingCount = $failing->count();
            $ratio = $failingCount / $total;

            if ($ratio < self::FAILURE_RATIO_THRESHOLD) {
                return null;
            }

            // Group failure categories so the operator sees "8 token_expired
            // + 2 rate_limited" rather than a 10-key list to eyeball.
            $byCategory = $failing->groupBy(function ($r) {
                return $r->failure_category ?? $r->last_poll_status ?? 'unknown';
            })->map->count()->sortDesc();

            $categoryBreakdown = $byCategory->map(function ($n, $cat) {
                return "{$n}×{$cat}";
            })->implode(', ');

            $sampleError = $failing->first()->last_error ?? '(no error captured)';
            if (strlen($sampleError) > 200) {
                $sampleError = substr($sampleError, 0, 197) . '...';
            }

            $percent = (int) round($ratio * 100);
            return [
                'title' => 'ESI fast-poll failing across pool',
                'message' => "{$failingCount}/{$total} enabled keys failing ({$percent}%). Categories: {$categoryBreakdown}. Sample error: {$sampleError}. See Diagnostics → API Status and the ESI Key Pool admin page.",
                'severity' => $ratio >= 0.95 ? 'critical' : 'warning',
                'context' => [
                    'failing_count' => $failingCount,
                    'pool_size' => $total,
                    'failure_ratio_percent' => $percent,
                    'breakdown' => $categoryBreakdown,
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('[MC Watchdog] EsiFastPollFailingCheck error: ' . $e->getMessage());
            return null;
        }
    }
}
