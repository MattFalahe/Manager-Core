<?php

namespace ManagerCore\Console\Commands;

use Illuminate\Console\Command;
use ManagerCore\Services\PluginBridge;
use ManagerCore\Models\PluginRegistry;
use ManagerCore\Models\TypeSubscription;
use ManagerCore\Models\MarketPrice;

class DiagnosePluginBridgeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manager-core:diagnose';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose Manager Core plugin bridge and services';

    /**
     * Execute the console command.
     *
     * @param PluginBridge $bridge
     * @return int
     */
    public function handle(PluginBridge $bridge)
    {
        $this->info('=== Manager Core Diagnostics ===');
        $this->newLine();

        // Plugin Bridge Status
        $this->info('Plugin Bridge Status:');
        $statistics = $bridge->getStatistics();
        $this->line("  Total Plugins: {$statistics['total_plugins']}");
        $this->line("  Active Plugins: {$statistics['active_plugins']}");
        $this->line("  Total Capabilities: {$statistics['total_capabilities']}");
        $this->newLine();

        // Registered Plugins
        $this->info('Registered Plugins:');
        $plugins = PluginRegistry::all();
        if ($plugins->isEmpty()) {
            $this->warn('  No plugins registered');
        } else {
            foreach ($plugins as $plugin) {
                $status = $plugin->is_active ? '✓' : '✗';
                $this->line("  [{$status}] {$plugin->plugin_name} - {$plugin->plugin_class}");
                if ($plugin->last_seen_at) {
                    $this->line("      Last seen: {$plugin->last_seen_at->diffForHumans()}");
                }
            }
        }
        $this->newLine();

        // Type Subscriptions
        $this->info('Type Subscriptions:');
        $subscriptions = TypeSubscription::select('plugin_name', 'market', \DB::raw('count(*) as count'))
            ->groupBy('plugin_name', 'market')
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->warn('  No type subscriptions');
        } else {
            foreach ($subscriptions as $sub) {
                $this->line("  {$sub->plugin_name} ({$sub->market}): {$sub->count} types");
            }
        }
        $this->newLine();

        // Market Price Status
        $this->info('Market Price Status:');
        $priceStats = MarketPrice::select('market', \DB::raw('count(distinct type_id) as types'))
            ->groupBy('market')
            ->get();

        if ($priceStats->isEmpty()) {
            $this->warn('  No market prices cached');
        } else {
            foreach ($priceStats as $stat) {
                $this->line("  {$stat->market}: {$stat->types} types tracked");
            }
        }
        $this->newLine();

        // Latest Price Update
        $latestUpdate = MarketPrice::orderBy('updated_at', 'desc')->first();
        if ($latestUpdate) {
            $this->info("Latest Price Update: {$latestUpdate->updated_at->diffForHumans()}");
        } else {
            $this->warn('No price updates yet');
        }

        $this->newLine();
        $this->info('=== Diagnostics Complete ===');

        return 0;
    }
}
