<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ManagerCore\Models\Market;
use ManagerCore\Services\ESI\MarketDataService;
use Seat\Web\Http\Controllers\Controller;

/**
 * Markets admin page.
 *
 * Lists every market MC knows about (hub + citadel) and lets superusers
 * add, edit, enable/disable, and test custom markets.
 *
 * Architecture (post 2026-05 pivot):
 *
 *   - HUB markets use ESI's public region orders endpoint. Type-filterable,
 *     fast, public. The 5 seeded hubs (Jita/Amarr/Dodixie/Hek/Rens) cover
 *     the common case; operators can add custom regional hubs.
 *
 *   - CITADEL markets are served by third-party appraisal services
 *     (Goonpraisal / Janice / etc.) selected per-market via the `provider`
 *     field. MC's own ESI scrape of /markets/structures/{id}/orders/ was
 *     removed because CCP's pagination is unreliable on large hubs (X-Pages
 *     reports N pages but pages 2..N return identical content to page 1).
 *
 *   - Each market row carries `provider` + `provider_slug`. The slug is
 *     the upstream service's market identifier (e.g. 'insmother' for
 *     Goonpraisal's C-J6MT view).
 */
class MarketsController extends Controller
{
    /**
     * Valid provider values. Keep in sync with PricingService::getPriceProvider().
     */
    protected const VALID_PROVIDERS = ['esi', 'janice', 'fuzzwork', 'goonpraisal', 'seat'];

    /**
     * GET /manager-core/markets
     */
    public function index()
    {
        $hubs = Market::where('market_type', Market::TYPE_HUB)
            ->orderBy('name')
            ->get();

        $citadels = Market::where('market_type', Market::TYPE_CITADEL)
            ->orderBy('name')
            ->get();

        // Per-plugin counts for the "this market is used by N plugins"
        // display column. Helps operators avoid deleting markets still
        // depended on by Buyback / Mining / etc.
        $pluginPreferences = DB::table('manager_core_pricing_preferences')
            ->select('market', DB::raw('COUNT(*) as plugin_count'))
            ->groupBy('market')
            ->pluck('plugin_count', 'market');

        return view('manager-core::markets.index', compact('hubs', 'citadels', 'pluginPreferences'));
    }

    /**
     * GET /manager-core/markets/create
     *
     * v1.0.0 — disabled. Custom citadel markets only work when the chosen
     * provider has a slug for the target hub. Without Janice slug expansion
     * or an Adam4EVE provider, any custom row outside the 7 pre-seeded
     * Goonpraisal rows would silently fall back to Jita. The button is
     * hidden from the Markets page; this guard handles direct URL hits
     * (operator with a bookmarked /markets/create) by bouncing back with
     * a clear message instead of 500ing.
     */
    public function create()
    {
        return redirect()
            ->route('manager-core.markets.index')
            ->with('warning',
                "Adding custom markets is disabled in v1.0.0 — custom citadels only " .
                "work if Goonpraisal has a slug for them. Use the 7 pre-seeded " .
                "Goonpraisal-backed markets, or wait for the v1.2 release (Janice slug " .
                "expansion + Adam4EVE provider).");
    }

    /**
     * POST /manager-core/markets
     *
     * v1.0.0 — disabled. See create() above for rationale.
     */
    public function store(Request $request)
    {
        return redirect()
            ->route('manager-core.markets.index')
            ->with('warning', 'Adding custom markets is disabled in v1.0.0.');
    }

    /**
     * GET /manager-core/markets/{id}/edit
     */
    public function edit(int $id)
    {
        $market = Market::findOrFail($id);

        if (!$market->is_custom) {
            return redirect()
                ->route('manager-core.markets.index')
                ->with('error', 'Default markets cannot be edited. Disable them via the enable toggle if needed.');
        }

        return view('manager-core::markets.form', [
            'market' => $market,
            'providers' => $this->providerOptions(),
            'isNew' => false,
        ]);
    }

    /**
     * PUT /manager-core/markets/{id}
     */
    public function update(Request $request, int $id)
    {
        $market = Market::findOrFail($id);

        if (!$market->is_custom) {
            return redirect()
                ->route('manager-core.markets.index')
                ->with('error', 'Default markets cannot be edited.');
        }

        $validated = $this->validateMarket($request, $market);

        $validated['system_ids'] = !empty($validated['system_id'])
            ? [(int) $validated['system_id']]
            : [];

        $market->update($validated);

        return redirect()
            ->route('manager-core.markets.index')
            ->with('success', "Market '{$market->name}' updated.");
    }

    /**
     * POST /manager-core/markets/{id}/toggle
     */
    public function toggle(int $id)
    {
        $market = Market::findOrFail($id);
        $market->is_enabled = !$market->is_enabled;
        $market->save();

        $action = $market->is_enabled ? 'enabled' : 'disabled';
        return back()->with('success', "Market '{$market->name}' {$action}.");
    }

    /**
     * DELETE /manager-core/markets/{id}
     *
     * Hard-delete a custom market. Refuses if any plugin still has a
     * pricing preference pointing at this market.
     */
    public function destroy(int $id)
    {
        $market = Market::findOrFail($id);

        if (!$market->is_custom) {
            return back()->with('error', 'Default markets cannot be deleted. Disable them instead if needed.');
        }

        $dependents = DB::table('manager_core_pricing_preferences')
            ->where('market', $market->key)
            ->count();

        if ($dependents > 0) {
            return back()->with('error',
                "Cannot delete: {$dependents} plugin(s) have pricing preferences pointing at this market. " .
                "Re-point those plugins to a different market first.");
        }

        $name = $market->name;
        $market->delete();

        return back()->with('success', "Market '{$name}' deleted.");
    }

    /**
     * POST /manager-core/markets/{id}/test
     *
     * Run a price fetch for one cheap type (Tritanium) through the
     * MARKET'S CONFIGURED PROVIDER and report whether it answered.
     *
     * Routes via PricingService (not MarketDataService directly) so the
     * per-market provider field is honoured: hub markets go through the
     * ESI region endpoint, citadel markets go through Goonpraisal /
     * Janice / etc. Without this, clicking "Test" on a Goonpraisal-backed
     * citadel market would silently try to ESI-scrape the structure,
     * which is exactly the broken path the third-party providers replace.
     */
    public function test(int $id, \ManagerCore\Services\PricingService $pricing)
    {
        $market = Market::findOrFail($id);

        // Catch the obvious misconfiguration up front rather than letting
        // MarketDataService log a confusing "citadel ESI fetch removed" line.
        if ($market->isCitadel() && $market->provider === 'esi') {
            return back()->with('warning',
                "This citadel market is configured to use the ESI provider, but MC's direct " .
                "structure-orders fetch was removed (CCP pagination bug). Change the provider " .
                "to Goonpraisal, Janice, or another third-party service.");
        }

        try {
            // Tritanium = type 34. Cheap to fetch, exists in nearly every
            // market — Goonpraisal/Janice/Fuzzwork all have a price for it.
            // PricingService::updatePrices resolves the per-market provider
            // and dispatches to the right class.
            $pricing->updatePrices($market->key, [34]);
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Test fetch for market '{$market->key}' threw: " . $e->getMessage());
            return back()->with('error',
                "Test fetch threw an exception: " . $e->getMessage() .
                " — see laravel.log for the full trace.");
        }

        $market->refresh();

        // Confirm Tritanium got a price recorded for this market. Most
        // providers don't write last_refresh_status on success (only the
        // ESI path does), so check the actual MarketPrice rows.
        $priced = \ManagerCore\Models\MarketPrice::where('market', $market->key)
            ->where('type_id', 34)
            ->exists();

        if ($priced) {
            return back()->with('success',
                "Test fetch succeeded for '{$market->name}' via provider '{$market->provider}'. " .
                "Tritanium price is now cached. You can select this market in any plugin's pricing preference.");
        }

        $errorTail = $market->last_refresh_error ? " Details: {$market->last_refresh_error}" : '';
        return back()->with('error',
            "Test fetch ran via provider '{$market->provider}' but no Tritanium price was recorded for '{$market->key}'. " .
            "Check the laravel.log for the provider's specific error." . $errorTail);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Standard form validation for store + update.
     */
    protected function validateMarket(Request $request, ?Market $existing = null): array
    {
        $rules = [
            'name'           => 'required|string|max:255',
            'key'            => 'nullable|string|max:64|regex:/^[a-z0-9-_]+$/',
            'market_type'    => 'required|in:hub,citadel',
            'is_enabled'     => 'nullable|boolean',
            'provider'       => ['required', 'string', 'in:' . implode(',', self::VALID_PROVIDERS)],
            'provider_slug'  => 'nullable|string|max:64',
            'region_id'      => 'required|integer|min:1',
            'system_id'      => 'nullable|integer|min:1',
            'system_name'    => 'nullable|string|max:64',
            'structure_name' => 'nullable|string|max:255',
        ];

        $validated = $request->validate($rules);
        $validated['is_enabled'] = (bool) ($validated['is_enabled'] ?? false);

        return $validated;
    }

    /**
     * Provider options for the dropdown. Each entry knows whether it's
     * available right now (e.g. Janice without an API key is disabled).
     * Shape: [ key => ['label' => string, 'available' => bool, 'note' => string] ]
     */
    protected function providerOptions(): array
    {
        $opts = [
            'esi' => [
                'label' => 'MCPraisal (ESI region endpoint)',
                'available' => true,
                'note' => 'Public hub markets — Jita, Amarr, Dodixie, Hek, Rens. No auth needed.',
            ],
            'fuzzwork' => [
                'label' => 'Fuzzwork',
                'available' => true,
                'note' => 'Community aggregator; hub markets only. No auth.',
            ],
            'janice' => [
                'label' => 'Janice',
                'available' => false,
                'note' => 'Appraisal service; needs API key configured in Settings.',
            ],
            'goonpraisal' => [
                'label' => 'Goonpraisal',
                'available' => true,
                'note' => 'Imperium nullsec hubs (C-J6MT, GB-6X5, UALX-3, etc.). No auth needed; respectful rate-limit applies.',
            ],
            'seat' => [
                'label' => 'SeAT Price Provider',
                'available' => false,
                'note' => 'seat-prices-core plugin; install + select one in Settings.',
            ],
        ];

        // Reflect actual availability based on each provider's isAvailable().
        try {
            $opts['janice']['available'] = (new \ManagerCore\Services\PriceProviders\JanicePriceProvider())->isAvailable();
        } catch (\Throwable $e) { /* leave false */ }
        try {
            $opts['seat']['available'] = (new \ManagerCore\Services\PriceProviders\SeatPriceProvider())->isAvailable();
        } catch (\Throwable $e) { /* leave false */ }

        return $opts;
    }
}
