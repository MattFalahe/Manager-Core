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
 * Architecture:
 *
 *   - HUB markets use ESI's public region orders endpoint. Type-filterable,
 *     fast, public. The 5 seeded hubs (Jita/Amarr/Dodixie/Hek/Rens) cover
 *     the common case; operators can add custom regional hubs.
 *
 *   - CITADEL markets are either read straight from the structure through
 *     ESI (provider 'esi', structure ID in the slug, read as an ESI Key
 *     Pool director) or priced by a third-party service that tracks the
 *     hub (Goonpraisal / Janice), selected per market via `provider`.
 *
 *   - Each market row carries `provider` + `provider_slug`. The slug is
 *     the provider's own identifier for the market: the structure ID for
 *     ESI, 'C-J6MT' for Goonpraisal's C-J6MT view.
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
     * Opens on a citadel market read through ESI, the case that needs no
     * third-party provider: any structure a Key Pool director can dock at.
     */
    public function create()
    {
        return view('manager-core::markets.form', [
            'market' => new Market([
                'market_type' => Market::TYPE_CITADEL,
                'provider' => 'esi',
                'is_enabled' => true,
            ]),
            'providers' => $this->providerOptions(),
            'isNew' => true,
        ]);
    }

    /**
     * POST /manager-core/markets
     */
    public function store(Request $request)
    {
        $validated = $this->hydrateStructureFields($this->validateMarket($request));

        if (empty($validated['region_id'])) {
            return back()->withInput()->withErrors([
                'region_id' => "Region ID is required. It couldn't be worked out from the structure.",
            ]);
        }

        if (empty($validated['key'])) {
            $validated['key'] = $this->defaultKey($validated);
        }

        if ($validated['key'] === '' || Market::where('key', $validated['key'])->exists()) {
            return back()->withInput()->withErrors([
                'key' => "Market key '{$validated['key']}' is empty or already taken. Pick a different key.",
            ]);
        }

        $validated['is_custom'] = true;
        $validated['system_ids'] = !empty($validated['system_id']) ? [(int) $validated['system_id']] : [];

        $market = Market::create($validated);

        return redirect()
            ->route('manager-core.markets.index')
            ->with('success', "Market '{$market->name}' added. Click Test on its row to check that it can be read.");
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

        $validated = $this->hydrateStructureFields($this->validateMarket($request, $market));

        if (empty($validated['region_id'])) {
            return back()->withInput()->withErrors([
                'region_id' => "Region ID is required. It couldn't be worked out from the structure.",
            ]);
        }

        // Plugins' pricing preferences point at the key, so it never changes.
        unset($validated['key']);

        $validated['system_ids'] = !empty($validated['system_id'])
            ? [(int) $validated['system_id']]
            : [];

        $readsChanged = ($validated['provider'] ?? null) !== $market->provider
            || ($validated['provider_slug'] ?? null) !== $market->provider_slug;

        $market->update($validated);

        // A different structure or provider makes the last read meaningless.
        if ($readsChanged) {
            app(MarketDataService::class)->forgetStructureRead($market->key);
        }

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
     * ESI region endpoint, Goonpraisal-backed citadel markets through
     * Goonpraisal, and so on. Citadel markets read through ESI get their
     * own test below.
     */
    public function test(int $id, \ManagerCore\Services\PricingService $pricing, MarketDataService $marketData)
    {
        $market = Market::findOrFail($id);

        if ($market->isCitadel() && $market->provider === 'esi') {
            return $this->testStructureMarket($market, $pricing, $marketData);
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

        // The status says whether the market's own provider answered. A
        // Tritanium row alone could be the fallback provider's, standing in
        // for a provider that failed.
        if ($market->last_refresh_status !== Market::STATUS_OK) {
            return back()->with('error',
                "Test fetch via provider '{$market->provider}' failed for '{$market->name}': " .
                ($market->last_refresh_error ?: 'see laravel.log for the provider\'s specific error.'));
        }

        $priced = \ManagerCore\Models\MarketPrice::where('market', $market->key)
            ->where('type_id', 34)
            ->exists();

        if ($priced) {
            return back()->with('success',
                "Test fetch succeeded for '{$market->name}' via provider '{$market->provider}'. " .
                "Tritanium price is now cached. You can select this market in any plugin's pricing preference.");
        }

        return back()->with('error',
            "Test fetch ran via provider '{$market->provider}', which answered, but it has no Tritanium price for '{$market->key}'.");
    }

    /**
     * Test a citadel market read through ESI with a fresh read, and report
     * what it found. Tritanium alone says little here, since a structure
     * may simply not sell it.
     */
    protected function testStructureMarket(Market $market, \ManagerCore\Services\PricingService $pricing, MarketDataService $marketData)
    {
        if (!$market->isStructureMarket()) {
            return back()->with('error',
                "'{$market->name}' is set to read through ESI, but its slug isn't a structure ID. " .
                "Edit the market and put the structure's ID in the slug field.");
        }

        $marketData->forgetStructureRead($market->key);

        try {
            $pricing->updatePrices($market->key, [34]);
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Test read for market '{$market->key}' threw: " . $e->getMessage());
            return back()->with('error',
                'Test read threw an exception: ' . $e->getMessage() . '. See laravel.log for the full trace.');
        }

        $market->refresh();
        $read = $marketData->lastStructureRead($market->key);

        if ($market->last_refresh_status === Market::STATUS_OK && $read) {
            return back()->with('success', sprintf(
                "Read '%s' as %s: %d page(s), %s orders. %d of the %d item(s) asked for have orders there.",
                $market->name,
                $read['character'],
                $read['pages'],
                number_format($read['orders']),
                $read['priced'],
                count($read['types'])
            ));
        }

        return back()->with('error',
            "Couldn't read '{$market->name}': " . ($market->last_refresh_error ?: 'see laravel.log for details.'));
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
            // Filled from the structure when left blank; checked after that.
            'region_id'      => 'nullable|integer|min:1',
            'system_id'      => 'nullable|integer|min:1',
            'system_name'    => 'nullable|string|max:64',
            'structure_name' => 'nullable|string|max:255',
        ];

        // A citadel market read through ESI is found by its structure ID.
        if ($request->input('market_type') === Market::TYPE_CITADEL && $request->input('provider') === 'esi') {
            $rules['provider_slug'] = ['required', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (Market::structureIdFromSlug($value) === null) {
                    $fail('For a citadel market read through ESI, the slug must be the structure ID: a 13-digit number such as 1049588174021.');
                }
            }];
        }

        $validated = $request->validate($rules);
        $validated['is_enabled'] = (bool) ($validated['is_enabled'] ?? false);

        if (isset($validated['provider_slug'])) {
            $validated['provider_slug'] = trim($validated['provider_slug']);
        }

        return $validated;
    }

    /**
     * Key for a new market left without one: the structure (or display)
     * name, led by its system unless the name already starts with it, as
     * SeAT's structure names do ("C-J6MT - 1st Taj Mahgoon").
     */
    protected function defaultKey(array $data): string
    {
        $label = trim((string) (($data['structure_name'] ?? null) ?: ($data['name'] ?? '')));
        $system = trim((string) ($data['system_name'] ?? ''));

        if ($system !== '' && stripos($label, $system) !== 0) {
            $label = $system . ' ' . $label;
        }

        return Str::slug($label);
    }

    /**
     * Fill a structure market's name, system and region from SeAT's own
     * data where the operator left them blank: universe_structures for the
     * name and system, the SDE for the system's name and region.
     */
    protected function hydrateStructureFields(array $data): array
    {
        $isStructure = ($data['market_type'] ?? null) === Market::TYPE_CITADEL
            && ($data['provider'] ?? null) === 'esi';
        $structureId = $isStructure ? Market::structureIdFromSlug($data['provider_slug'] ?? null) : null;

        if (!$structureId) {
            return $data;
        }

        try {
            $structure = DB::table('universe_structures')->where('structure_id', $structureId)->first();
            if ($structure) {
                $data['structure_name'] = ($data['structure_name'] ?? null) ?: $structure->name;
                $data['system_id'] = ($data['system_id'] ?? null) ?: $structure->solar_system_id;
            }

            if (!empty($data['system_id']) && (empty($data['system_name']) || empty($data['region_id']))) {
                $system = DB::table('mapDenormalize')->where('itemID', $data['system_id'])->first();
                if ($system) {
                    $data['system_name'] = ($data['system_name'] ?? null) ?: $system->itemName;
                    $data['region_id'] = ($data['region_id'] ?? null) ?: $system->regionID;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Could not look up structure {$structureId} in SeAT: " . $e->getMessage());
        }

        return $data;
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
                'label' => 'MCPraisal (ESI)',
                'available' => true,
                'note' => 'Hub markets: the public region order book, no auth needed. Citadel markets: reads the structure\'s whole market as an ESI Key Pool director who can dock there. Put the structure ID in the slug field.',
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
