<?php

/*
|--------------------------------------------------------------------------
| Manager Core API Routes
|--------------------------------------------------------------------------
|
| Token-authenticated API endpoints for external tools.
| All routes are prefixed with /api/manager-core/v1/
| Authentication via Authorization: Bearer header (or X-Api-Token).
|
| Every route declares its required scope via mc-api-auth:<scope>.
| Tokens default to READ_ONLY_SCOPES; write scopes must be explicitly granted.
|
*/

Route::group([
    'namespace' => 'ManagerCore\Http\Controllers\Api',
    'prefix' => 'api/manager-core/v1',
], function () {

    // Prices — read scope
    Route::get('/prices/{typeId}', 'PriceApiController@getPrice')
        ->where('typeId', '[0-9]+')
        ->middleware(['mc-api-auth:prices.read', 'mc-api-rate-limit']);

    Route::post('/prices/batch', 'PriceApiController@getPrices')
        ->middleware(['mc-api-auth:prices.read', 'mc-api-rate-limit']);

    Route::get('/prices/{typeId}/trend', 'PriceApiController@getTrend')
        ->where('typeId', '[0-9]+')
        ->middleware(['mc-api-auth:prices.read', 'mc-api-rate-limit']);

    // Appraisals
    Route::post('/appraisals', 'AppraisalApiController@create')
        ->middleware(['mc-api-auth:appraisals.create', 'mc-api-rate-limit']);

    Route::get('/appraisals/{appraisalId}', 'AppraisalApiController@show')
        ->middleware(['mc-api-auth:appraisals.read', 'mc-api-rate-limit']);

    // Plugins — read scope
    Route::get('/plugins', 'PluginApiController@index')
        ->middleware(['mc-api-auth:plugins.read', 'mc-api-rate-limit']);

    Route::get('/plugins/{pluginName}', 'PluginApiController@show')
        ->middleware(['mc-api-auth:plugins.read', 'mc-api-rate-limit']);

    Route::get('/subscriptions', 'PluginApiController@subscriptions')
        ->middleware(['mc-api-auth:plugins.read', 'mc-api-rate-limit']);

    // Events — write/read scopes
    Route::post('/events/publish', 'EventApiController@publish')
        ->middleware(['mc-api-auth:events.publish', 'mc-api-rate-limit']);

    Route::get('/events/log', 'EventApiController@log')
        ->middleware(['mc-api-auth:events.read', 'mc-api-rate-limit']);
});
