<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Models\Setting;
use ManagerCore\Models\Market;

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

        $settings = [
            'cache_ttl' => Setting::get('pricing.cache_ttl', 3600),
            'default_market' => Setting::get('pricing.default_market', 'jita'),
            'retention_days' => Setting::get('appraisal.retention_days', 90),
            'auto_discovery' => Setting::get('bridge.auto_discovery', true),
            'update_frequency' => Setting::get('pricing.update_frequency', 240),
        ];

        return view('manager-core::settings.index', compact('markets', 'settings'));
    }

    /**
     * Save general settings
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function save(Request $request)
    {
        $request->validate([
            'cache_ttl' => 'required|integer|min:60|max:86400',
            'default_market' => 'required|string',
            'retention_days' => 'required|integer|min:0|max:3650',
            'auto_discovery' => 'boolean',
            'update_frequency' => 'required|integer|min:60|max:1440',
        ]);

        Setting::set('pricing.cache_ttl', (int) $request->input('cache_ttl'), 'pricing');
        Setting::set('pricing.default_market', $request->input('default_market'), 'pricing');
        Setting::set('appraisal.retention_days', (int) $request->input('retention_days'), 'appraisal');
        Setting::set('bridge.auto_discovery', $request->boolean('auto_discovery'), 'bridge');
        Setting::set('pricing.update_frequency', (int) $request->input('update_frequency'), 'pricing');

        return back()->with('success', 'Settings saved successfully');
    }

    /**
     * Toggle market enabled status
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function toggleMarket($id)
    {
        $market = Market::findOrFail($id);
        $market->is_enabled = !$market->is_enabled;
        $market->save();

        $status = $market->is_enabled ? 'enabled' : 'disabled';
        return back()->with('success', "Market '{$market->name}' {$status} successfully");
    }

    /**
     * Show form to add custom market
     *
     * @return \Illuminate\View\View
     */
    public function addMarket()
    {
        return view('manager-core::settings.add-market');
    }

    /**
     * Store custom market
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeMarket(Request $request)
    {
        $request->validate([
            'key' => 'required|string|alpha_dash|unique:manager_core_markets,key|max:50',
            'name' => 'required|string|max:255',
            'region_id' => 'required|integer',
            'system_ids' => 'required|string',
        ]);

        // Parse system IDs
        $systemIds = array_map('intval', array_filter(explode(',', $request->input('system_ids'))));

        if (empty($systemIds)) {
            return back()->withInput()->with('error', 'At least one system ID is required');
        }

        Market::create([
            'key' => $request->input('key'),
            'name' => $request->input('name'),
            'region_id' => $request->input('region_id'),
            'system_ids' => $systemIds,
            'is_enabled' => true,
            'is_custom' => true,
        ]);

        return redirect()->route('manager-core.settings')
            ->with('success', 'Custom market added successfully');
    }

    /**
     * Delete custom market
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deleteMarket($id)
    {
        $market = Market::findOrFail($id);

        if (!$market->is_custom) {
            return back()->with('error', 'Cannot delete default markets');
        }

        $market->delete();

        return back()->with('success', "Market '{$market->name}' deleted successfully");
    }
}
