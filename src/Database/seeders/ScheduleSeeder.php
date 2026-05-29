<?php

namespace ManagerCore\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    /**
     * Override AbstractScheduleSeeder::run() so existing installs pick up
     * cron-expression changes on subsequent deploys.
     *
     * The base class only inserts when the command does not already exist
     * (insert-if-missing), which means changing an `expression` in
     * getSchedules() never propagates to installs that already have the row.
     *
     * `updateOrInsert` keyed on `command` reconciles every field on each run
     * and matches the pattern used by Buyback Manager + HR Manager + SM v3.1+.
     */
    public function run(): void
    {
        foreach ($this->getSchedules() as $job) {
            DB::table('schedules')->updateOrInsert(
                ['command' => $job['command']],
                $job
            );
        }

        $deprecated = $this->getDeprecatedSchedules();
        if (! empty($deprecated)) {
            DB::table('schedules')->whereIn('command', $deprecated)->delete();
        }
    }

    /**
     * Return the scheduled commands for this plugin
     *
     * @return array
     */
    public function getSchedules(): array
    {
        return [
            [
                'command' => 'manager-core:update-prices',
                'expression' => '0 */4 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            [
                'command' => 'manager-core:cleanup',
                'expression' => '0 3 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            [
                'command' => 'manager-core:cleanup-events',
                'expression' => '0 4 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            [
                'command' => 'manager-core:cleanup-stale-subscriptions',
                'expression' => '0 5 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],

            // ESI notification fast-poll: every 2 minutes.
            //
            // This expression MUST stay in sync with
            // PollEsiNotifications::SCHEDULE_INTERVAL_SECONDS (120). The job's
            // adaptive batch formula sizes each run as
            //   ceil(pool * SCHEDULE_INTERVAL_SECONDS / TARGET_PER_CHAR_INTERVAL)
            // so every director is polled roughly once per CCP notifications-
            // cache window (~10 min). If this cron fires MORE often than
            // SCHEDULE_INTERVAL_SECONDS, the formula under-sizes the batch and
            // every director ends up polled proportionally too often — wasting
            // ESI error budget on cache hits.
            //
            // (Was briefly every-minute in 2026-05; the later adaptive-batch +
            // per-corp fair LRU rework made that unnecessary — detection speed
            // now scales via batch size, not cron frequency. Reverting to */2
            // also matches the "~2 minutes per corp" figure the suite advertises.)
            [
                'command' => 'manager-core:poll-esi-notifications',
                'expression' => '*/2 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],

            // SeAT-table fallback sweep: every minute.
            // Bumped from */10 to *. SeAT itself only refreshes
            // character_notifications on its 20-30 minute bucket, so the
            // detection floor is set by SeAT (not this sweep). Running every
            // minute means MC picks up new rows immediately when SeAT writes
            // them, instead of adding up to 9 extra minutes of delay on top
            // of SeAT's bucket. The job is bounded (LIMIT 200, type filter,
            // 2h cutoff, allow_overlap=false) so the every-minute cadence is
            // safe.
            [
                'command' => 'manager-core:sweep-seat-notifications',
                'expression' => '* * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],

            // Watchdog: every 5 minutes.
            // Runs MC's internal health checks (EventBus failures, price
            // cron overdue, ESI pool failing, providers unavailable) and
            // posts alerts directly to a Discord/Slack webhook. Bypasses
            // EventBus by design — circular dependency if MC's own bus
            // is broken. Dedups per check on a 1h Redis key so the same
            // condition doesn't ping 12 times an hour. Honours configured
            // exclusion windows (default 11:00-11:10 UTC = EVE downtime).
            //
            // Cheap when nothing's wrong (~4 small SELECTs + a settings
            // read). When something IS wrong it fires once then stays
            // quiet for 1h. Disabled by default — operator opts in via
            // Settings → Watchdog tab.
            [
                'command' => 'manager-core:watchdog',
                'expression' => '*/5 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
        ];
    }

    /**
     * Return commands that should be removed from the schedule
     *
     * @return array
     */
    public function getDeprecatedSchedules(): array
    {
        return [
            // Removed in the 2026-05-27 pivot to third-party providers
            // (Goonpraisal/Janice/Fuzzwork). The citadel ESI scrape never
            // worked reliably for large nullsec hubs (CCP pagination
            // collapse — pages 2..N return identical content to page 1).
            // Two-step swap pattern: emit here so SeAT prunes the cron row
            // on installs that ran the 2026-05-26 build.
            'manager-core:poll-citadel-markets',
        ];
    }
}
