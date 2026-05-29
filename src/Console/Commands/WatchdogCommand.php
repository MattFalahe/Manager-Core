<?php

namespace ManagerCore\Console\Commands;

use Illuminate\Console\Command;
use ManagerCore\Services\Watchdog\WatchdogService;

/**
 * Runs MC's Watchdog — meta-monitoring of MC's own infrastructure.
 *
 * Scheduled every 5 minutes via ScheduleSeeder. Each tick runs the
 * registered checks, fires alerts that breach their threshold (with
 * 1-hour dedup), and posts to the configured Discord/Slack webhook
 * directly (NOT through EventBus — that's the whole point of the
 * watchdog: monitor the systems that other notification plugins depend on).
 *
 * Manual invocation:
 *   php artisan manager-core:watchdog
 *   php artisan manager-core:watchdog --dry-run   (run checks but skip delivery)
 *
 * Returns 0 on success regardless of whether alerts fired — this is
 * monitoring, not a build step.
 */
class WatchdogCommand extends Command
{
    protected $signature = 'manager-core:watchdog
                            {--dry-run : Run checks but skip webhook delivery (just logs what would have fired)}';

    protected $description = 'Run MC Watchdog health checks + deliver alerts via configured webhook';

    public function handle(WatchdogService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Watchdog dry-run: checks will run but no webhook delivery.');
        }

        $summary = $service->run($dryRun);

        $this->table(['Key', 'Value'], collect($summary)->except('alerts')->map(function ($v, $k) {
            return [$k, is_bool($v) ? ($v ? 'true' : 'false') : (string) $v];
        })->values()->toArray());

        if (!empty($summary['alerts'])) {
            $this->info('Alerts:');
            $this->table(
                ['Check', 'Severity', 'Title', 'Delivered?'],
                collect($summary['alerts'])->map(function ($a) {
                    return [$a['name'], $a['severity'], $a['title'], $a['delivered'] ? 'yes' : 'no'];
                })->toArray()
            );
        }

        return self::SUCCESS;
    }
}
