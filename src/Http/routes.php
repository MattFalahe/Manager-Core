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
});
