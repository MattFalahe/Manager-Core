<?php

namespace ManagerCore\Services\Watchdog\Checks;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Services\PricingService;
use ManagerCore\Services\Watchdog\WatchdogCheck;

/**
 * Alert when the scheduled price refresh hasn't stored a price for
 * noticeably longer than its configured cadence.
 *
 * Failure modes this catches:
 *   - SeAT scheduler is dead (no cron ticks reaching the queue)
 *   - The update-prices command itself is throwing
 *   - All providers are unreachable (no rows updated)
 *   - Cron expression got nuked from the schedules table somehow
 *
 * Threshold: the last scheduled refresh that stored a price is more than
 * 2× the cron interval ago.
 *
 * It reads the time the scheduled refresh itself keeps, not the newest
 * price in the table: a plugin may ask for prices between the scheduled
 * runs, and those would keep the newest price young over a dead schedule.
 * Until a scheduled refresh has kept its time once (the first run after
 * the update that added it), the newest price is still what it goes by.
 */
class PriceCronOverdueCheck implements WatchdogCheck
{
    /** Falls back here when the schedules row is missing / unparseable. */
    private const FALLBACK_INTERVAL_MINUTES = 240;

    public function name(): string { return 'price_cron_overdue'; }
    public function label(): string { return 'Price cron overdue'; }
    public function description(): string
    {
        return 'Alerts when the scheduled price refresh has not stored a price for more than 2× its interval. Prices a plugin asks for between the scheduled runs do not count. Indicates SeAT scheduler is dead, the command is throwing, or all providers are unreachable.';
    }

    public function run(): ?array
    {
        try {
            $totalRows = $this->priceRowCount();
            if ($totalRows === 0) {
                // Fresh install with no prices yet — not a watchdog alert
                // (operator just needs to wait for the first cron tick).
                return null;
            }

            $intervalMin = $this->resolveCronIntervalMinutes();
            $thresholdMin = $intervalMin * 2;

            $scheduled = $this->lastScheduledRefreshAt();
            $last = $scheduled ?? $this->newestPriceAt();
            if (!$last) {
                return null;
            }

            $ageMin = (int) $last->diffInMinutes(Carbon::now());

            if ($ageMin <= $thresholdMin) {
                return null;
            }

            $what = $scheduled
                ? "The scheduled price refresh last stored a price {$ageMin}min ago. Prices a plugin asked for since do not count."
                : "Newest cached price is {$ageMin}min old.";

            return [
                'title' => 'Price cron overdue',
                'message' => "{$what} Cron is scheduled every {$intervalMin}min, threshold is 2× = {$thresholdMin}min. Run `manager-core:update-prices` manually to test the command. See Diagnostics → Overview for provider + cache state.",
                'severity' => $ageMin >= $thresholdMin * 3 ? 'critical' : 'warning',
                'context' => [
                    ($scheduled ? 'last_scheduled_refresh_age_min' : 'newest_price_age_min') => $ageMin,
                    'cron_interval_min' => $intervalMin,
                    'threshold_min' => $thresholdMin,
                    ($scheduled ? 'last_scheduled_refresh_at' : 'newest_price_at') => (string) $last,
                    'total_cached_rows' => $totalRows,
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('[MC Watchdog] PriceCronOverdueCheck error: ' . $e->getMessage());
            return null;
        }
    }

    protected function priceRowCount(): int
    {
        return (int) MarketPrice::count();
    }

    /** When a scheduled refresh last stored a price, if one has kept its time yet. */
    protected function lastScheduledRefreshAt(): ?Carbon
    {
        return PricingService::lastScheduledRefreshAt();
    }

    protected function newestPriceAt(): ?Carbon
    {
        $newest = MarketPrice::max('updated_at');

        return $newest ? Carbon::parse($newest) : null;
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
