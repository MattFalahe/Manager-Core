<?php

namespace ManagerCore\Console\Commands;

use Illuminate\Console\Command;
use ManagerCore\Models\ApiTokenUsage;
use ManagerCore\Models\EventLog;
use ManagerCore\Models\SettingsAudit;

/**
 * Daily cleanup of three append-only forensic tables:
 *   - manager_core_event_log         (events.event_log_retention_days, default 30)
 *   - manager_core_api_token_usage   (api.token_usage_retention_days, default 30)
 *   - manager_core_settings_audit    (settings.audit_retention_days, default 365)
 */
class CleanupEventLogCommand extends Command
{
    protected $signature = 'manager-core:cleanup-events
                            {--days= : Override retention days for events (legacy convenience flag)}';

    protected $description = 'Clean up old event log, API token usage, and settings audit entries';

    public function handle(): int
    {
        $explicitDays = $this->option('days');

        // Events
        $eventDays = $explicitDays ?? config('manager-core.events.event_log_retention_days', 30);
        $eventCount = EventLog::where('created_at', '<', now()->subDays((int) $eventDays))->delete();
        $this->info("[Manager Core] Cleaned up {$eventCount} event_log entries older than {$eventDays} days");

        // L22: API token usage history
        try {
            $tokenUsageDays = config('manager-core.api.token_usage_retention_days', 30);
            $tokenUsageCount = ApiTokenUsage::where('created_at', '<', now()->subDays((int) $tokenUsageDays))->delete();
            $this->info("[Manager Core] Cleaned up {$tokenUsageCount} api_token_usage entries older than {$tokenUsageDays} days");
        } catch (\Throwable $e) {
            $this->warn('[Manager Core] api_token_usage cleanup skipped: ' . $e->getMessage());
        }

        // L14: settings audit log (longer default — these are operator-relevant)
        try {
            $auditDays = config('manager-core.settings.audit_retention_days', 365);
            $auditCount = SettingsAudit::where('created_at', '<', now()->subDays((int) $auditDays))->delete();
            $this->info("[Manager Core] Cleaned up {$auditCount} settings_audit entries older than {$auditDays} days");
        } catch (\Throwable $e) {
            $this->warn('[Manager Core] settings_audit cleanup skipped: ' . $e->getMessage());
        }

        return Command::SUCCESS;
    }
}
