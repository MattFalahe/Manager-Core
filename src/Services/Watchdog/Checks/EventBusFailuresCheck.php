<?php

namespace ManagerCore\Services\Watchdog\Checks;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\EventLog;
use ManagerCore\Services\Watchdog\WatchdogCheck;

/**
 * Alert when EventBus dispatch failures spike.
 *
 * Failure modes this catches:
 *   - A subscriber's capability handler started throwing (newly deployed
 *     plugin with a bug)
 *   - Queue worker dead so queued events pile up as failed
 *   - Circuit breaker opening across multiple subscribers
 *   - Database write contention on manager_core_event_log
 *
 * Threshold: 10 failed/partial_failure events in the last 60 minutes.
 * Tuned for Matt's install scale (~50-200 events/hour typical). Operators
 * with much higher event volume might want a higher threshold; not
 * settings-exposed in v1.0.0 (premature; revisit if false positives).
 */
class EventBusFailuresCheck implements WatchdogCheck
{
    private const FAILURE_THRESHOLD = 10;
    private const LOOKBACK_MINUTES = 60;

    public function name(): string { return 'eventbus_failures'; }
    public function label(): string { return 'EventBus dispatch failures'; }
    public function description(): string
    {
        return 'Alerts when ' . self::FAILURE_THRESHOLD . '+ events fail or partially fail in the last '
            . self::LOOKBACK_MINUTES . ' minutes. Indicates a subscriber handler is throwing, queue worker is dead, or a circuit breaker has tripped.';
    }

    public function run(): ?array
    {
        try {
            $cutoff = Carbon::now()->subMinutes(self::LOOKBACK_MINUTES);
            $failed = EventLog::where('created_at', '>=', $cutoff)
                ->whereIn('status', ['failed', 'partial_failure'])
                ->count();

            if ($failed < self::FAILURE_THRESHOLD) {
                return null;
            }

            // Surface the most-recent error message + offending publisher
            // so the operator has a starting point without opening Event Trace
            $lastFailed = EventLog::where('created_at', '>=', $cutoff)
                ->whereIn('status', ['failed', 'partial_failure'])
                ->orderBy('created_at', 'desc')
                ->first(['publisher_plugin', 'event_name', 'errors']);

            $sampleError = '(no error detail)';
            if ($lastFailed && is_array($lastFailed->errors) && !empty($lastFailed->errors)) {
                // errors is typically [ ['subscriber'=>..., 'error'=>...] ]
                $first = is_array($lastFailed->errors[0] ?? null) ? $lastFailed->errors[0] : null;
                $sampleError = $first['error'] ?? json_encode($lastFailed->errors[0] ?? null);
                if (strlen($sampleError) > 200) {
                    $sampleError = substr($sampleError, 0, 197) . '...';
                }
            }

            return [
                'title' => 'EventBus dispatch failures',
                'message' => "{$failed} failed/partial-failure events in last " . self::LOOKBACK_MINUTES . "min. Latest from publisher '{$lastFailed->publisher_plugin}' on event '{$lastFailed->event_name}': {$sampleError}. See Diagnostics → Event Trace for per-event detail.",
                'severity' => $failed >= self::FAILURE_THRESHOLD * 5 ? 'critical' : 'warning',
                'context' => [
                    'failure_count' => $failed,
                    'window' => self::LOOKBACK_MINUTES . 'min',
                    'last_publisher' => $lastFailed->publisher_plugin ?? 'unknown',
                    'last_event' => $lastFailed->event_name ?? 'unknown',
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('[MC Watchdog] EventBusFailuresCheck error: ' . $e->getMessage());
            return null;
        }
    }
}
