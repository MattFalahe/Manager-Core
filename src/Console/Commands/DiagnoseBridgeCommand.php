<?php

namespace ManagerCore\Console\Commands;

use Illuminate\Console\Command;
use ManagerCore\Services\PluginBridge;
use ManagerCore\Models\PluginRegistry;

class DiagnoseBridgeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manager-core:diagnose-bridge
                            {--refresh : Refresh plugin discovery}
                            {--detailed : Show detailed plugin information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose Plugin Bridge connectivity and plugin status';

    /**
     * Execute the console command.
     *
     * @param PluginBridge $bridge
     * @return int
     */
    public function handle(PluginBridge $bridge)
    {
        $this->displayHeader();

        // Refresh discovery if requested
        if ($this->option('refresh')) {
            $this->info('🔄 Refreshing plugin discovery...');
            $bridge->discoverPlugins();
            $this->info('✅ Discovery completed' . PHP_EOL);
        }

        $this->displayPluginRegistry();
        $this->displayCapabilities($bridge);
        $this->displayRecommendations();

        return 0;
    }

    /**
     * Display the header
     */
    protected function displayHeader()
    {
        $this->newLine();
        $this->line('╔════════════════════════════════════════════════════════════╗');
        $this->line('║   Manager Core - Plugin Bridge Diagnostic Report          ║');
        $this->line('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    /**
     * Display plugin registry information
     */
    protected function displayPluginRegistry()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('🔌 REGISTERED PLUGINS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $plugins = PluginRegistry::all();

        if ($plugins->isEmpty()) {
            $this->warn('  ⚠️  No plugins registered yet');
            $this->newLine();
            return;
        }

        $tableData = [];
        foreach ($plugins as $plugin) {
            $status = $this->getStatusIcon($plugin->status);
            $tableData[] = [
                $plugin->plugin_name,
                $plugin->version ?? 'N/A',
                $status . ' ' . ucfirst($plugin->status),
                $plugin->updated_at->diffForHumans(),
            ];
        }

        $this->table(
            ['Plugin Name', 'Version', 'Status', 'Last Seen'],
            $tableData
        );

        // Summary
        $activeCount = $plugins->where('status', 'active')->count();
        $totalCount = $plugins->count();

        $this->line("  📊 Total Plugins: {$totalCount}");
        $this->line("  ✅ Active: {$activeCount}");
        $this->newLine();
    }

    /**
     * Display plugin capabilities
     */
    protected function displayCapabilities(PluginBridge $bridge)
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('🎯 PLUGIN CAPABILITIES');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $plugins = PluginRegistry::where('status', 'active')->get();

        if ($plugins->isEmpty()) {
            $this->warn('  ⚠️  No active plugins to show capabilities');
            $this->newLine();
            return;
        }

        foreach ($plugins as $plugin) {
            $capabilities = $plugin->capabilities ?? [];

            $this->line("  <fg=cyan>{$plugin->plugin_name}</>");

            if (empty($capabilities)) {
                $this->line("    <fg=gray>No capabilities registered</>");
            } else {
                foreach ($capabilities as $capability) {
                    $this->line("    • {$capability}");
                }
            }
            $this->newLine();
        }
    }

    /**
     * Display recommendations
     */
    protected function displayRecommendations()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('💡 RECOMMENDATIONS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $plugins = PluginRegistry::all();
        $activePlugins = $plugins->where('status', 'active');

        $recommendations = [];

        if ($plugins->isEmpty()) {
            $recommendations[] = [
                '⚠️',
                'No plugins discovered',
                'Install compatible Manager Suite plugins',
            ];
        }

        if ($activePlugins->isEmpty() && $plugins->isNotEmpty()) {
            $recommendations[] = [
                '❌',
                'No active plugins',
                'Check plugin status and errors',
            ];
        }

        if ($activePlugins->count() < 2) {
            $recommendations[] = [
                'ℹ️',
                'Limited ecosystem',
                'Consider installing more Manager Suite plugins for full functionality',
            ];
        }

        if (empty($recommendations)) {
            $this->info('  ✅ All checks passed! Plugin Bridge is healthy.');
        } else {
            $this->table(
                ['Status', 'Issue', 'Solution'],
                $recommendations
            );
        }

        $this->newLine();
        $this->line('  💡 Tip: Run with --refresh to rediscover plugins');
        $this->line('  💡 Tip: Run with --detailed for more information');
        $this->newLine();
    }

    /**
     * Get status icon for plugin
     */
    protected function getStatusIcon(string $status): string
    {
        return match($status) {
            'active' => '🟢',
            'inactive' => '⚪',
            'error' => '🔴',
            'development' => '🟠',
            default => '❓',
        };
    }
}
