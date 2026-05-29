<?php

namespace ManagerCore\Services\Watchdog\Checks;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Services\Watchdog\WatchdogCheck;

/**
 * Alert when the price-refresh cron hasn't run for noticeably longer
 * than its configured cadence.
 *
 * Failure modes this catches:
 *   - SeAT scheduler is dead (no cron ticks reaching the queue)
 *   - The update-prices command itself is throwing
 *   - All providers are unreachable (no rows updated)
 *   - Cron expression got nuked from the schedules table somehow
 *
 * Threshold: newest MarketPrice.updated_at is more than 2× the cron
 * interval old. Default cron is `0 *_/4 * * *` = every 240 minutes,
 * so anything > 480 minutes (8 hours) old fires the alert.
 */
class PriceCronOverdueCheck implements WatchdogCheck
{
    /** Falls back here when the schedules row is missing / unparseable. */
    private const FALLBACK_INTERVAL_MINUTES = 240;

    public function name(): string { return 'price_cron_overdue'; }
    public function label(): string { return 'Price cron overdue'; }
    public function description(): string
    {
        return 'Alerts when the newest cached market price is older than 2× the scheduled cron interval. Indicates SeAT scheduler is dead, the command is throwing, or all providers are unreachable.';
    }

    public function run(): ?array
    {
        try {
            $totalRows = MarketPrice::count();
            if ($totalRows === 0) {
                // Fresh install with no prices yet — not a watchdog alert
                // (operator just needs to wait for the first cron tick).
                return null;
            }

            $intervalMin = $this->resolveCronIntervalMinutes();
            $thresholdMin = $intervalMin * 2;

            $newestRaw = MarketPrice::max('updated_at');
            if (!$newestRaw) {
                return null;
            }

            $newest = Carbon::parse($newestRaw);
            $ageMin = (int) $newest->diffInMinutes(Carbon::now());

            if ($ageMin <= $thresholdMin) {
                return null;
            }

            return [
                'title' => 'Price cron overdue',
                'message' => "Newest cached price is {$ageMin}min old. Cron is scheduled every {$intervalMin}min, threshold is 2× = {$thresholdMin}min. Run `manager-core:update-prices` manually to test the command. See Diagnostics → Overview for provider + cache state.",
                'severity' => $ageMin >= $thresholdMin * 3 ? 'critical' : 'warning',
                'context' => [
                    'newest_price_age_min' => $ageMin,
                    'cron_interval_min' => $intervalMin,
                    'threshold_min' => $thresholdMin,
                    'newest_price_at' => (string) $newest,
                    'total_cached_rows' => $totalRows,
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('[MC Watchdog] PriceCronOverdueCheck error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse the actual cron expression from the schedules table back into
     * minutes. Mirrors DiagnosticController::resolvePriceRefreshIntervalMinutes
     * — kept duplicated rather than extracted because the dependency would
     * pull DiagnosticController into the watchdog's call chain (which is
     * supposed to be self-contained for watchdog's monitor-the-monitor role).
     */
    protected function resolveCronIntervalMinutes(): int
    {
        try {
            $row = DB::table('schedules')
                ->where('command', 'manager-core:update-prices')
                ->first(['expression']);
            if (!$row || empty($row->expression)) {
                return self::FALLBACK_INTERVAL_MINUTES;
            }
            $expr = trim((string) $row->expression);
            // "0 */N * * *" → N hours
            if (preg_match('/^0 \*\/(\d+) \* \* \*$/', $expr, $m)) {
                return ((int) $m[1]) * 60;
            }
            if ($expr === '0 0 * * *') return 1440;
            if ($expr === '0 * * * *') return 60;
            // "*/N * * * *" → N minutes
            if (preg_match('/^\*\/(\d+) \* \* \* \*$/', $expr, $m)) {
                return (int) $m[1];
            }
            return self::FALLBACK_INTERVAL_MINUTES;
        } catch (\Throwable $e) {
            return self::FALLBACK_INTERVAL_MINUTES;
        }
    }
}
