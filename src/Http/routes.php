<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'namespace'  => 'ManagerCore\Http\Controllers',
    'prefix'     => 'manager-core',
    'middleware' => ['web', 'auth', 'locale'],
], function () {

    // Dashboard
    Route::get('/', [
        'as'   => 'manager-core.index',
        'uses' => 'DashboardController@index',
        'middleware' => 'can:manager-core.view',
    ]);

    Route::get('/dashboard', [
        'as'   => 'manager-core.dashboard',
        'uses' => 'DashboardController@index',
        'middleware' => 'can:manager-core.view',
    ]);

    // Help & Documentation
    Route::get('/help', [
        'as'   => 'manager-core.help',
        'uses' => 'HelpController@index',
        'middleware' => 'can:manager-core.view',
    ]);

    // Appraisal Routes
    Route::group(['prefix' => 'appraisal'], function () {
        Route::get('/', [
            'as'   => 'manager-core.appraisal.index',
            'uses' => 'AppraisalController@index',
            'middleware' => 'can:manager-core.appraisal',
        ]);

        Route::post('/create', [
            'as'   => 'manager-core.appraisal.create',
            'uses' => 'AppraisalController@create',
            'middleware' => 'can:manager-core.appraisal',
        ]);

        Route::get('/{appraisal}', [
            'as'   => 'manager-core.appraisal.show',
            'uses' => 'AppraisalController@show',
        ]);

        Route::delete('/{appraisal}', [
            'as'   => 'manager-core.appraisal.delete',
            'uses' => 'AppraisalController@delete',
            'middleware' => 'can:manager-core.appraisal',
        ]);
    });

    // Pricing Routes
    Route::group(['prefix' => 'pricing'], function () {
        Route::get('/', [
            'as'   => 'manager-core.pricing.index',
            'uses' => 'PricingController@index',
            'middleware' => 'can:manager-core.pricing.view',
        ]);

        Route::get('/type/{typeId}', [
            'as'   => 'manager-core.pricing.type',
            'uses' => 'PricingController@showType',
            'middleware' => 'can:manager-core.pricing.view',
        ]);

        Route::post('/subscribe', [
            'as'   => 'manager-core.pricing.subscribe',
            'uses' => 'PricingController@subscribe',
            'middleware' => 'can:manager-core.pricing.manage',
        ]);
    });

    // Plugin Bridge Routes
    Route::group(['prefix' => 'bridge'], function () {
        Route::get('/', [
            'as'   => 'manager-core.bridge.index',
            'uses' => 'PluginBridgeController@index',
            'middleware' => 'can:manager-core.bridge.view',
        ]);

        Route::post('/refresh', [
            'as'   => 'manager-core.bridge.refresh',
            'uses' => 'PluginBridgeController@refresh',
            'middleware' => 'can:manager-core.bridge.manage',
        ]);

        // Flush EcosystemVersionChecker's 6-hour Packagist cache so the
        // next page render fetches fresh version info for every plugin.
        Route::post('/refresh-versions', [
            'as'   => 'manager-core.bridge.refresh-versions',
            'uses' => 'PluginBridgeController@refreshVersions',
            'middleware' => 'can:manager-core.bridge.manage',
        ]);
    });

    // Settings Routes
    Route::get('/settings', [
        'as'   => 'manager-core.settings',
        'uses' => 'SettingsController@index',
        'middleware' => 'can:global.superuser',
    ]);

    Route::post('/settings', [
        'as'   => 'manager-core.settings.save',
        'uses' => 'SettingsController@save',
        'middleware' => 'can:global.superuser',
    ]);

    // Watchdog: meta-monitoring settings + test webhook button.
    // Lives under settings/ for URL discoverability; permission is
    // superuser-only (same as general settings) since misconfiguration
    // would spam the operator's chat channels.
    Route::post('/settings/watchdog', [
        'as'   => 'manager-core.settings.watchdog.save',
        'uses' => 'SettingsController@saveWatchdog',
        'middleware' => 'can:global.superuser',
    ]);

    Route::post('/settings/watchdog/test', [
        'as'   => 'manager-core.settings.watchdog.test',
        'uses' => 'SettingsController@testWatchdogWebhook',
        'middleware' => 'can:global.superuser',
    ]);

    // (Removed v1.0.0 release prep)
    // manager-core.settings.market.{toggle,add,store,delete} routes were
    // orphans pre-pivot — the markets-on-settings-page workflow was
    // replaced by the standalone /manager-core/markets admin in commit
    // 270e7e2. The routes + the SettingsController::{addMarket,storeMarket,
    // toggleMarket,deleteMarket} methods + settings/add-market.blade.php
    // were unreachable from any UI link for several weeks. Dropped here
    // to avoid carrying ~200 lines of dead code into v1.0.0.

    // Markets admin (hub + third-party-provider-backed citadel markets)
    // — superuser only since pricing affects every plugin's economic data
    // and provider routing changes can silently shift downstream behavior.
    Route::group(['prefix' => 'markets', 'middleware' => 'can:global.superuser'], function () {
        Route::get('/', [
            'as'   => 'manager-core.markets.index',
            'uses' => 'MarketsController@index',
        ]);

        Route::get('/create', [
            'as'   => 'manager-core.markets.create',
            'uses' => 'MarketsController@create',
        ]);

        Route::post('/', [
            'as'   => 'manager-core.markets.store',
            'uses' => 'MarketsController@store',
        ]);

        Route::get('/{id}/edit', [
            'as'   => 'manager-core.markets.edit',
            'uses' => 'MarketsController@edit',
        ]);

        Route::put('/{id}', [
            'as'   => 'manager-core.markets.update',
            'uses' => 'MarketsController@update',
        ]);

        // Quick enable/disable toggle from the index page
        Route::post('/{id}/toggle', [
            'as'   => 'manager-core.markets.toggle',
            'uses' => 'MarketsController@toggle',
        ]);

        // Test fetch — routes a Tritanium price request through the
        // market's configured provider end-to-end so operators verify
        // the upstream is reachable before relying on it in production
        Route::post('/{id}/test', [
            'as'   => 'manager-core.markets.test',
            'uses' => 'MarketsController@test',
        ]);

        Route::delete('/{id}', [
            'as'   => 'manager-core.markets.destroy',
            'uses' => 'MarketsController@destroy',
        ]);
    });

    // Per-plugin pricing preferences (admin UI)
    Route::group(['prefix' => 'pricing-preferences', 'middleware' => 'can:global.superuser'], function () {
        Route::get('/', [
            'as'   => 'manager-core.pricing-preferences.index',
            'uses' => 'PricingPreferencesController@index',
        ]);

        Route::post('/{id}', [
            'as'   => 'manager-core.pricing-preferences.update',
            'uses' => 'PricingPreferencesController@update',
        ]);

        Route::post('/{id}/reset', [
            'as'   => 'manager-core.pricing-preferences.reset',
            'uses' => 'PricingPreferencesController@reset',
        ]);
    });

    // Type Subscription Routes
    Route::group(['prefix' => 'subscriptions'], function () {
        Route::get('/', [
            'as'   => 'manager-core.subscriptions.index',
            'uses' => 'SubscriptionController@index',
            'middleware' => 'can:manager-core.pricing.manage',
        ]);

        Route::post('/subscribe-types', [
            'as'   => 'manager-core.subscriptions.subscribe-types',
            'uses' => 'SubscriptionController@subscribeTypes',
            'middleware' => 'can:manager-core.pricing.manage',
        ]);

        Route::post('/subscribe-category', [
            'as'   => 'manager-core.subscriptions.subscribe-category',
            'uses' => 'SubscriptionController@subscribeCategory',
            'middleware' => 'can:manager-core.pricing.manage',
        ]);

        Route::post('/subscribe-group', [
            'as'   => 'manager-core.subscriptions.subscribe-group',
            'uses' => 'SubscriptionController@subscribeGroup',
            'middleware' => 'can:manager-core.pricing.manage',
        ]);

        Route::delete('/{id}', [
            'as'   => 'manager-core.subscriptions.unsubscribe',
            'uses' => 'SubscriptionController@unsubscribe',
            'middleware' => 'can:manager-core.pricing.manage',
        ]);

        Route::delete('/plugin/clear', [
            'as'   => 'manager-core.subscriptions.clear-plugin',
            'uses' => 'SubscriptionController@clearPlugin',
            'middleware' => 'can:manager-core.pricing.manage',
        ]);

        // AJAX Routes
        Route::get('/categories', [
            'as'   => 'manager-core.subscriptions.categories',
            'uses' => 'SubscriptionController@getCategories',
            'middleware' => 'can:manager-core.pricing.manage',
        ]);

        Route::get('/groups/{categoryId}', [
            'as'   => 'manager-core.subscriptions.groups',
            'uses' => 'SubscriptionController@getGroups',
            'middleware' => 'can:manager-core.pricing.manage',
        ]);
    });

    // Diagnostics (superuser only)
    Route::group(['prefix' => 'diagnostic', 'middleware' => 'can:global.superuser'], function () {

        Route::get('/', [
            'as'   => 'manager-core.diagnostic.index',
            'uses' => 'DiagnosticController@index',
        ]);

        Route::get('/system-overview', [
            'as'   => 'manager-core.diagnostic.system-overview',
            'uses' => 'DiagnosticController@systemOverview',
        ]);

        Route::get('/master-test', [
            'as'   => 'manager-core.diagnostic.master-test',
            'uses' => 'DiagnosticController@masterTest',
        ]);

        Route::get('/event-trace', [
            'as'   => 'manager-core.diagnostic.event-trace',
            'uses' => 'DiagnosticController@eventTrace',
        ]);

        Route::get('/event-trace/{id}', [
            'as'   => 'manager-core.diagnostic.event-trace-detail',
            'uses' => 'DiagnosticController@eventTraceDetail',
        ]);

        Route::post('/test-plugin/{plugin}', [
            'as'   => 'manager-core.diagnostic.test-plugin',
            'uses' => 'DiagnosticController@testPluginConnection',
        ]);

        Route::post('/test-all-plugins', [
            'as'   => 'manager-core.diagnostic.test-all-plugins',
            'uses' => 'DiagnosticController@testAllPlugins',
        ]);

        Route::post('/test-price-provider', [
            'as'   => 'manager-core.diagnostic.test-price-provider',
            'uses' => 'DiagnosticController@testPriceProvider',
        ]);

        Route::get('/subscription-health', [
            'as'   => 'manager-core.diagnostic.subscription-health',
            'uses' => 'DiagnosticController@subscriptionHealth',
        ]);

        Route::get('/cache-health', [
            'as'   => 'manager-core.diagnostic.cache-health',
            'uses' => 'DiagnosticController@cacheHealth',
        ]);

        Route::get('/event-bus-health', [
            'as'   => 'manager-core.diagnostic.event-bus-health',
            'uses' => 'DiagnosticController@eventBusHealth',
        ]);

        Route::get('/capabilities', [
            'as'   => 'manager-core.diagnostic.capabilities',
            'uses' => 'DiagnosticController@capabilitiesOverview',
        ]);

        Route::post('/test-event', [
            'as'   => 'manager-core.diagnostic.test-event',
            'uses' => 'DiagnosticController@testEventPublish',
        ]);

        Route::get('/api-health', [
            'as'   => 'manager-core.diagnostic.api-health',
            'uses' => 'DiagnosticController@apiHealth',
        ]);

        Route::post('/test-esi', [
            'as'   => 'manager-core.diagnostic.test-esi',
            'uses' => 'DiagnosticController@testEsiConnectivity',
        ]);

        Route::post('/test-market', [
            'as'   => 'manager-core.diagnostic.test-market',
            'uses' => 'DiagnosticController@testMarket',
        ]);

        Route::get('/settings-health', [
            'as'   => 'manager-core.diagnostic.settings-health',
            'uses' => 'DiagnosticController@settingsHealth',
        ]);

        // Watchdog Notification Testing tab — preview every Watchdog
        // check's alert format in the configured Discord/Slack channel
        // without manufacturing the real failure condition.
        Route::get('/watchdog-testing', [
            'as'   => 'manager-core.diagnostic.watchdog-testing',
            'uses' => 'DiagnosticController@watchdogTesting',
        ]);

        Route::post('/simulate-watchdog', [
            'as'   => 'manager-core.diagnostic.simulate-watchdog',
            'uses' => 'DiagnosticController@simulateWatchdog',
        ]);
    });

    // ESI Key Pool Management (superuser only - shared across plugins)
    Route::group(['prefix' => 'esi-key-pool', 'middleware' => 'can:global.superuser'], function () {

        Route::get('/', [
            'as'   => 'manager-core.esi-key-pool.index',
            'uses' => 'EsiKeyPoolController@index',
        ]);

        Route::get('/list', [
            'as'   => 'manager-core.esi-key-pool.list',
            'uses' => 'EsiKeyPoolController@getKeyHolders',
        ]);

        Route::get('/eligible', [
            'as'   => 'manager-core.esi-key-pool.eligible',
            'uses' => 'EsiKeyPoolController@getEligibleCharacters',
        ]);

        Route::post('/add', [
            'as'   => 'manager-core.esi-key-pool.add',
            'uses' => 'EsiKeyPoolController@add',
        ]);

        Route::post('/{id}/toggle', [
            'as'   => 'manager-core.esi-key-pool.toggle',
            'uses' => 'EsiKeyPoolController@toggle',
        ]);

        Route::post('/{id}/resume', [
            'as'   => 'manager-core.esi-key-pool.resume',
            'uses' => 'EsiKeyPoolController@resume',
        ]);

        Route::delete('/{id}', [
            'as'   => 'manager-core.esi-key-pool.remove',
            'uses' => 'EsiKeyPoolController@remove',
        ]);

        Route::post('/poll-now', [
            'as'   => 'manager-core.esi-key-pool.poll-now',
            'uses' => 'EsiKeyPoolController@pollNow',
        ]);
    });

    // API Token Management
    Route::group(['prefix' => 'api-tokens', 'middleware' => 'can:manager-core.api.manage'], function () {

        Route::get('/', [
            'as'   => 'manager-core.api-tokens.index',
            'uses' => 'ApiTokenController@index',
        ]);

        Route::post('/', [
            'as'   => 'manager-core.api-tokens.store',
            'uses' => 'ApiTokenController@store',
        ]);

        Route::delete('/{id}', [
            'as'   => 'manager-core.api-tokens.destroy',
            'uses' => 'ApiTokenController@destroy',
        ]);

        Route::post('/{id}/toggle', [
            'as'   => 'manager-core.api-tokens.toggle',
            'uses' => 'ApiTokenController@toggle',
        ]);

        // L21: rotate the raw token in place
        Route::post('/{id}/rotate', [
            'as'   => 'manager-core.api-tokens.rotate',
            'uses' => 'ApiTokenController@rotate',
        ]);
    });
});
