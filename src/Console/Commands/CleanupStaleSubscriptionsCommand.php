<?php

namespace ManagerCore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\EventSubscription;
use ManagerCore\Models\PluginRegistry;

/**
 * M2 fix: clean up event subscriptions and registry entries from plugins
 * that are no longer installed (composer remove'd).
 *
 * Detects "stale" subscribers by checking each subscriber_plugin against the
 * compatible_plugins config: if the plugin's service-provider class no longer
 * exists, its subscriptions are deactivated (not deleted — operator decides
 * via --delete flag) and PluginRegistry's is_active flag is cleared.
 *
 * Run via schedule (daily) or manually:
 *   docker exec -it seat-docker-front-1 php artisan manager-core:cleanup-stale-subscriptions [--delete]
 */
class CleanupStaleSubscriptionsCommand extends Command
{
    protected $signature = 'manager-core:cleanup-stale-subscriptions
                            {--delete : Delete stale subscriptions instead of deactivating them}
                            {--dry-run : Print what would be done without changing anything}';

    protected $description = 'Deactivate or delete event subscriptions and registry entries from uninstalled plugins';

    public function handle(): int
    {
        $delete = $this->option('delete');
        $dryRun = $this->option('dry-run');

        $compatible = config('manager-core.bridge.compatible_plugins', []);
        $knownPlugins = array_keys($compatible);

        // Get all distinct subscriber_plugin values currently in use
        $subscriberPlugins = EventSubscription::distinct()
            ->pluck('subscriber_plugin')
            ->toArray();

        $stalePlugins = [];

        foreach ($subscriberPlugins as $pluginName) {
            // Skip ManagerCore's own subscriptions
            if ($pluginName === 'manager-core' || $pluginName === 'ManagerCore') {
                continue;
            }

            // If the plugin is in compatible_plugins, check whether its class still loads
            if (isset($compatible[$pluginName])) {
                $class = $compatible[$pluginName]['class'] ?? null;
                if ($class && class_exists($class)) {
                    continue; // installed and loadable — not stale
                }
                $stalePlugins[$pluginName] = "class {$class} no longer loadable";
                continue;
            }

            // Plugin is not in the compatible_plugins config — could be unknown or self-registered
            // Look at PluginRegistry first
            try {
                $registry = PluginRegistry::where('plugin_name', $pluginName)->first();
                if ($registry && $registry->plugin_class && class_exists($registry->plugin_class)) {
                    continue; // self-registered and loadable
                }
            } catch (\Throwable $e) {
                // Registry table missing — treat as stale conservatively
            }

            $stalePlugins[$pluginName] = 'not in compatible_plugins config and no registry row with loadable class';
        }

        if (empty($stalePlugins)) {
            $this->info('[Manager Core] No stale subscriptions found.');
            return Command::SUCCESS;
        }

        $this->warn('[Manager Core] Stale plugins detected:');
        foreach ($stalePlugins as $name => $reason) {
            $count = EventSubscription::where('subscriber_plugin', $name)->count();
            $this->line("  - {$name} ({$count} subscriptions): {$reason}");
        }

        if ($dryRun) {
            $this->info('Dry run — no changes made. Re-run without --dry-run to apply.');
            return Command::SUCCESS;
        }

        $totalAffected = 0;
        foreach (array_keys($stalePlugins) as $name) {
            if ($delete) {
                $affected = EventSubscription::where('subscriber_plugin', $name)->delete();
                $this->line("  - Deleted {$affected} subscription(s) for {$name}");
            } else {
                $affected = EventSubscription::where('subscriber_plugin', $name)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
                $this->line("  - Deactivated {$affected} subscription(s) for {$name}");
            }
            $totalAffected += $affected;

            try {
                PluginRegistry::where('plugin_name', $name)->update(['is_active' => false]);
            } catch (\Throwable $e) {
                Log::warning("[Manager Core] Could not update PluginRegistry for {$name}: " . $e->getMessage());
            }
        }

        $verb = $delete ? 'deleted' : 'deactivated';
        $this->info("[Manager Core] {$verb} {$totalAffected} stale subscription(s) across " . count($stalePlugins) . ' plugin(s).');

        return Command::SUCCESS;
    }
}
