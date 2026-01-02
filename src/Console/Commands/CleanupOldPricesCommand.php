<?php

namespace ManagerCore\Console\Commands;

use Illuminate\Console\Command;
use ManagerCore\Models\PriceHistory;
use ManagerCore\Services\AppraisalService;

class CleanupOldPricesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manager-core:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup old price history and expired appraisals';

    /**
     * Execute the console command.
     *
     * @param AppraisalService $appraisalService
     * @return int
     */
    public function handle(AppraisalService $appraisalService)
    {
        $this->info('[Manager Core] Starting cleanup...');

        // Cleanup old price history
        $retentionDays = config('manager-core.pricing.history_retention_days', 90);
        $cutoffDate = now()->subDays($retentionDays);

        $deletedHistory = PriceHistory::where('date', '<', $cutoffDate)->delete();

        if ($deletedHistory > 0) {
            $this->info("[Manager Core] Deleted {$deletedHistory} old price history records");
        }

        // Cleanup expired appraisals
        $deletedAppraisals = $appraisalService->deleteExpiredAppraisals();

        if ($deletedAppraisals > 0) {
            $this->info("[Manager Core] Deleted {$deletedAppraisals} expired appraisals");
        }

        $this->info('[Manager Core] Cleanup completed');
        return 0;
    }
}
