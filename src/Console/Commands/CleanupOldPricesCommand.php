<?php

namespace ManagerCore\Console\Commands;

use Illuminate\Console\Command;
use ManagerCore\Models\PriceHistory;
use ManagerCore\Services\AppraisalService;
use ManagerCore\Services\PricingService;

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
    protected $description = 'Cleanup old price history, expired appraisals and provider prices no plugin asks for';

    /**
     * Execute the console command.
     *
     * @param AppraisalService $appraisalService
     * @param PricingService   $pricingService
     * @return int
     */
    public function handle(AppraisalService $appraisalService, PricingService $pricingService)
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

        // A plugin that stops watching an item and starts again within the
        // window gets its last price back at once.
        $deletedVariants = $pricingService->pruneVariants((int) config('manager-core.pricing.variants.unsubscribed_retention_days', 7));

        if ($deletedVariants > 0) {
            $this->info("[Manager Core] Deleted {$deletedVariants} provider price(s) no plugin subscribes to any more");
        }

        $this->info('[Manager Core] Cleanup completed');
        return 0;
    }
}
