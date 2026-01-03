<?php

namespace ManagerCore;

use Seat\Services\AbstractSeatPlugin;
use ManagerCore\Console\Commands\UpdateMarketPricesCommand;
use ManagerCore\Console\Commands\CleanupOldPricesCommand;
use ManagerCore\Console\Commands\DiagnosePluginBridgeCommand;
use ManagerCore\Database\Seeders\ScheduleSeeder;

class ManagerCoreServiceProvider extends AbstractSeatPlugin
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        // Check if routes are cached before loading
        if (!$this->app->routesAreCached()) {
            include __DIR__ . '/Http/routes.php';
        }

        $this->loadTranslationsFrom(__DIR__ . '/Resources/lang/', 'manager-core');
        $this->loadViewsFrom(__DIR__ . '/Resources/views/', 'manager-core');

        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations/');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                UpdateMarketPricesCommand::class,
                CleanupOldPricesCommand::class,
                DiagnosePluginBridgeCommand::class,
                \ManagerCore\Console\Commands\DiagnoseCommand::class,
                \ManagerCore\Console\Commands\DiagnoseESICommand::class,
            ]);
        }

        // Add publications
        $this->add_publications();

        // Boot the plugin bridge
        $this->bootPluginBridge();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        // Register sidebar configuration
        $this->mergeConfigFrom(
            __DIR__ . '/Config/Menu/package.sidebar.php',
            'package.sidebar'
        );

        // Register permissions
        $this->registerPermissions(
            __DIR__ . '/Config/Permissions/manager-core.permissions.php',
            'manager-core'
        );

        // Register config
        $this->mergeConfigFrom(
            __DIR__ . '/Config/manager-core.config.php',
            'manager-core'
        );

        // Register core services as singletons
        $this->app->singleton(\ManagerCore\Services\PluginBridge::class);
        $this->app->singleton(\ManagerCore\Services\PricingService::class);
        $this->app->singleton(\ManagerCore\Services\AppraisalService::class);
        $this->app->singleton(\ManagerCore\Services\ParserService::class);

        // Add database seeders
        $this->add_database_seeders();
    }

    /**
     * Boot the Plugin Bridge system
     *
     * This discovers and registers all compatible plugins
     *
     * @return void
     */
    private function bootPluginBridge()
    {
        $bridge = $this->app->make(\ManagerCore\Services\PluginBridge::class);
        $bridge->discover();
    }

    /**
     * Add content which must be published.
     */
    private function add_publications()
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/Config/manager-core.config.php' => config_path('manager-core.php'),
        ], ['config', 'seat']);

        // Publish assets
        $this->publishes([
            __DIR__ . '/Resources/assets' => public_path('vendor/manager-core'),
        ], ['public', 'seat']);
    }

    /**
     * Register database seeders
     */
    private function add_database_seeders()
    {
        $this->registerDatabaseSeeders([
            ScheduleSeeder::class,
        ]);
    }

    /**
     * Get the plugin name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Manager Core';
    }

    /**
     * Get the plugin repository URL.
     *
     * @return string
     */
    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/MattFalahe/manager-core';
    }

    /**
     * Get the packagist package name.
     *
     * @return string
     */
    public function getPackagistPackageName(): string
    {
        return 'manager-core';
    }

    /**
     * Get the packagist vendor name.
     *
     * @return string
     */
    public function getPackagistVendorName(): string
    {
        return 'mattfalahe';
    }
}
