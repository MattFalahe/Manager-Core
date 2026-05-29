<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Models\Setting;
use ManagerCore\Models\Market;
use ManagerCore\Services\Watchdog\WatchdogService;

class SettingsController extends Controller
{
    /**
     * Display settings page
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $markets = Market::getAllMarkets();

        // Note: 'price_provider' is no longer in this list — the global
        // default concept was removed. Resolution chain is now per-appraisal
        // override → per-market provider column on manager_core_markets →
        // hard-coded fail-safe in PricingService. Operators configure
        // per-market provider on the Markets admin page; credentials below
        // are kept so they're configurable even when no current market
        // uses that provider yet.
        $settings = [
            'seat_price_provider' => Setting::get('pricing.seat_provider', ''),
            'cache_ttl' => Setting::get('pricing.cache_ttl', 3600),
            'default_market' => Setting::get('pricing.default_market', 'jita'),
            'retention_days' => Setting::get('appraisal.retention_days', 90),
            // A2 fix: read from 'bridge.auto_discover' (the key the bridge actually uses).
            // Fall back to the legacy 'bridge.auto_discovery' key for installs that
            // wrote a value before the key was corrected, then to true.
            'auto_discovery' => Setting::get('bridge.auto_discover', Setting::get('bridge.auto_discovery', true)),
            'janice_api_key' => Setting::get('pricing.janice_api_key', config('manager-core.pricing.janice.api_key', '')),
            'goonpraisal_contact_email' => Setting::get('pricing.goonpraisal_contact_email', config('manager-core.pricing.goonpraisal.contact_email', 'mattfalahe@gmail.com')),
        ];

        // Get available SeAT price providers
        $availableProviders = \ManagerCore\Services\PriceProviders\SeatPriceProvider::getAvailableProviders();

        // Watchdog settings — surfaced as a separate group so the partial
        // can render its tab without a second roundtrip. Values come from
        // the manager_core_settings table same as everything else; the
        // service is the single source of truth for defaults.
        $watchdog = app(WatchdogService::class);
        $watchdogState = [
            'enabled'           => $watchdog->isEnabled(),
            'webhook_url'       => $watchdog->getWebhookUrl(),
            'exclusion_windows' => $watchdog->getExclusionWindows(),
            'checks'            => collect($watchdog->getChecks())->map(function ($c) use ($watchdog) {
                return [
                    'name'        => $c->name(),
                    'label'       => $c->label(),
                    'description' => $c->description(),
                    'enabled'     => $watchdog->isCheckEnabled($c->name()),
                ];
            })->all(),
        ];

        return view('manager-core::settings.index', compact('markets', 'settings', 'availableProviders', 'watchdogState'));
    }

    /**
     * Save general settings
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function save(Request $request)
    {
        $availableSeatProviders = \ManagerCore\Services\PriceProviders\SeatPriceProvider::getAvailableProviders();

        $request->validate([
            // 'price_provider' removed — see settings controller index() note.
            'seat_price_provider' => 'nullable|string|' . (empty($availableSeatProviders) ? '' : 'in:' . implode(',', $availableSeatProviders)),
            'cache_ttl' => 'required|integer|min:60|max:86400',
            'default_market' => 'required|string',
            'retention_days' => 'required|integer|min:0|max:3650',
            'auto_discovery' => 'boolean',
            // Janice API key: free-form string from janice.e-351.com. We store
            // it as a plain Setting (the manager_core_settings table is admin-
            // only and not exposed via REST API), no length limit because
            // future Janice key formats might be longer than today's ~32-char.
            'janice_api_key' => 'nullable|string|max:512',
            // Goonpraisal contact email — embedded in our User-Agent header
            // per their docs request. Defaults to plugin maintainer's email
            // so installs work without operator config, but operators are
            // encouraged to set their own so Goonpraisal can reach the
            // actual person if there's ever a problem with their queries.
            'goonpraisal_contact_email' => 'nullable|email|max:255',
        ]);

        // H8: explicit list of setting keys we write — kept aligned with the readers
        // so a control on the form maps to a setting that's actually consulted.
        // The cache_ttl value is stored in SECONDS to match PricingService usage.
        $cacheTtlSeconds = (int) $request->input('cache_ttl');
        if ($cacheTtlSeconds < 60) {
            $cacheTtlSeconds = 60;
        }

        $writes = [
            'pricing.seat_provider'         => $request->input('seat_price_provider', ''),
            'pricing.cache_ttl'             => $cacheTtlSeconds,
            'pricing.default_market'        => $request->input('default_market'),
            'appraisal.retention_days'      => (int) $request->input('retention_days'),
            // H8: align setting key with the config key actually read by PluginBridge
            'bridge.auto_discover'          => $request->boolean('auto_discovery'),
            'pricing.janice_api_key'        => $request->input('janice_api_key', ''),
            'pricing.goonpraisal_contact_email' => $request->input('goonpraisal_contact_email', 'mattfalahe@gmail.com'),
        ];

        $groups = [
            'pricing.seat_provider' => 'pricing',
            'pricing.cache_ttl' => 'pricing',
            'pricing.default_market' => 'pricing',
            'appraisal.retention_days' => 'appraisal',
            'bridge.auto_discover' => 'bridge',
            'pricing.janice_api_key' => 'pricing',
            'pricing.goonpraisal_contact_email' => 'pricing',
        ];

        foreach ($writes as $key => $value) {
            // L14: capture old value for the audit log before overwriting
            $oldValue = Setting::get($key, '__MC_NOT_SET__');

            Setting::set($key, $value, $groups[$key] ?? 'general');
            \ManagerCore\Helpers\Settings::forget($key);

            // Skip the audit write for the sentinel "never set before" state
            if ($oldValue === '__MC_NOT_SET__') {
                $oldValue = null;
            }
            \ManagerCore\Models\SettingsAudit::record($key, $oldValue, $value, 'settings_ui');
        }

        // H5: clear bridge discovery cache since auto_discover or related state may have changed
        try {
            app(\ManagerCore\Services\PluginBridge::class)->clearCache();
        } catch (\Throwable $e) {
            // Bridge may not be resolvable in some contexts; not fatal
        }

        // Note: update_frequency used to be a settable input here, with
        // applyUpdateFrequencyToSchedule() rewriting the schedules row to
        // match. Removed in v1.0.0 — the ScheduleSeeder reconciles the row
        // back to its hardcoded expression on every container restart, so
        // any operator override would only survive until the next deploy.
        // The Settings page now shows the cron as read-only. Operators who
        // genuinely need a different cadence edit ScheduleSeeder.php.

        return back()->with('success', 'Settings saved successfully');
    }

    // (Removed v1.0.0 release prep)
    // toggleMarket / addMarket / storeMarket / deleteMarket actions all
    // dropped along with their routes and the settings/add-market.blade.php
    // view. The "settings.market.*" workflow was orphaned when the
    // standalone /manager-core/markets admin shipped in commit 270e7e2 —
    // no UI link reached these endpoints for several weeks. The single
    // source of truth for market admin is now MarketsController.

    /**
     * Save Watchdog settings.
     *
     * The watchdog is MC's own self-monitoring layer. It is DELIBERATELY
     * decoupled from EventBus so failures of the bus itself can still
     * reach the operator. Settings here live in manager_core_settings
     * under the 'watchdog' group.
     *
     * Webhook URL is validated as a URL but we don't restrict to Discord
     * or Slack hosts at validation time — the service auto-detects the
     * payload format from URL pattern (discord.com/api/webhooks vs
     * hooks.slack.com/services). Unknown patterns log a warning instead
     * of silently dropping.
     */
    public function saveWatchdog(Request $request)
    {
        $watchdog = app(WatchdogService::class);
        $checkNames = collect($watchdog->getChecks())->map->name()->all();

        // Per-check toggle validation rules — build dynamically so adding
        // a new check class doesn't require a controller edit. Each toggle
        // is a boolean cast from the form's checkbox (`1` when checked,
        // missing key when unchecked).
        $rules = [
            'enabled'           => 'sometimes|boolean',
            'webhook_url'       => 'nullable|url|max:2048',
            'exclusion_windows' => 'nullable|string|max:512|regex:/^(\s*\d{1,2}:\d{2}-\d{1,2}:\d{2}\s*)(,\s*\d{1,2}:\d{2}-\d{1,2}:\d{2}\s*)*$/',
        ];
        foreach ($checkNames as $name) {
            $rules['check_' . $name] = 'sometimes|boolean';
        }

        $request->validate($rules, [
            'exclusion_windows.regex' => 'Exclusion windows must be one or more HH:MM-HH:MM UTC ranges separated by commas (e.g. "11:00-11:10, 23:55-00:05")',
        ]);

        $writes = [
            'watchdog.enabled'           => $request->boolean('enabled'),
            'watchdog.webhook_url'       => trim((string) $request->input('webhook_url', '')),
            'watchdog.exclusion_windows' => trim((string) $request->input('exclusion_windows', '11:00-11:10')),
        ];

        foreach ($checkNames as $name) {
            $writes['watchdog.check.' . $name . '.enabled'] = $request->boolean('check_' . $name, true);
        }

        foreach ($writes as $key => $value) {
            $oldValue = Setting::get($key, '__MC_NOT_SET__');
            Setting::set($key, $value, 'watchdog');
            \ManagerCore\Helpers\Settings::forget($key);

            if ($oldValue === '__MC_NOT_SET__') {
                $oldValue = null;
            }
            \ManagerCore\Models\SettingsAudit::record($key, $oldValue, $value, 'settings_ui');
        }

        return back()->with('success', 'Watchdog settings saved');
    }

    /**
     * Fire a test webhook from the Settings UI. Bypasses dedup and
     * exclusion windows so operators can verify URL + format immediately.
     * Returns JSON for the AJAX call from the partial's "Test webhook"
     * button.
     */
    public function testWatchdogWebhook(Request $request)
    {
        $result = app(WatchdogService::class)->fireTestAlert();
        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
