<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ManagerCore\Models\ESI\EsiKeyHolder;
use ManagerCore\Models\Market;
use ManagerCore\Services\ESI\MarketDataService;
use ManagerCore\Services\MarketPlaces;
use ManagerCore\Services\PriceProviders\FuzzworkPriceProvider;
use ManagerCore\Services\PriceProviders\JanicePriceProvider;
use ManagerCore\Services\PricingService;
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

    /** The order citadel markets are grouped in, by provider. */
    protected const CITADEL_GROUPS = ['goonpraisal', 'esi', 'janice', 'fuzzwork', 'seat'];

    /**
     * GET /manager-core/markets
     *
     * Hubs show what every provider can do there. Citadel markets are
     * grouped by the provider that prices them.
     */
    public function index(PricingService $pricing)
    {
        $markets = Market::orderBy('name')->get();
        $rows = $markets->map(fn (Market $market) => $market->toArray())->all();
        $places = new MarketPlaces($pricing, $rows);
        $names = $places->rowNames($rows);
        $listed = $pricing->listMarkets(false);
        $readers = $this->readersByProvider();
        $keyPool = $this->keyPoolSize();

        $chips = [];
        $where = [];
        foreach ($markets as $market) {
            $chips[$market->key] = MarketPlaces::chips($market->name, $listed[$market->key]['providers'] ?? [], $readers[$market->key] ?? []);
            $where[$market->key] = $this->placeLine($market, $names);
        }

        // Per-plugin counts for the "this market is used by N plugins"
        // display column. Helps operators avoid deleting markets still
        // depended on by Buyback / Mining / etc.
        $pluginPreferences = DB::table('manager_core_pricing_preferences')
            ->select('market', DB::raw('COUNT(*) as plugin_count'))
            ->groupBy('market')
            ->pluck('plugin_count', 'market');

        return view('manager-core::markets.index', [
            'hubs' => $markets->where('market_type', Market::TYPE_HUB)->values(),
            'citadelGroups' => $this->citadelGroups($markets->where('market_type', Market::TYPE_CITADEL)),
            'tiles' => $this->providerTiles($markets, $places, $keyPool),
            'chips' => $chips,
            'where' => $where,
            'keyPool' => $keyPool,
            'pluginPreferences' => $pluginPreferences,
        ]);
    }

    /**
     * GET /manager-core/markets/places
     *
     * Suggestions for the Add and Edit form, from SeAT's own tables: with
     * `q`, the places matching it; without, the one place the fields name.
     */
    public function places(Request $request, PricingService $pricing)
    {
        $places = $this->placeFinder($pricing, (int) $request->query('market_id', 0) ?: null);
        $type = $request->query('type') === Market::TYPE_HUB ? Market::TYPE_HUB : Market::TYPE_CITADEL;

        if ($request->filled('q')) {
            $results = $places->search($type, mb_substr((string) $request->query('q'), 0, 64), $request->query('only') === 'system' ? 'system' : null);

            return response()->json(['results' => $results, 'note' => $places->note()]);
        }

        $place = $places->resolve(['market_type' => $type]
            + $request->only(['provider_slug', 'structure_id', 'structure_name', 'system_id', 'system_name', 'region_id']));

        return response()->json(['place' => $place, 'note' => $places->note()]);
    }

    /**
     * GET /manager-core/markets/create
     *
     * Opens on a citadel market, the case the form's search helps with
     * most: a structure found by its name.
     */
    public function create(PricingService $pricing)
    {
        return $this->form(new Market([
            'market_type' => Market::TYPE_CITADEL,
            'is_enabled' => true,
        ]), true, $pricing);
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

        if ($placeError = self::placeError($validated, $validated['key'])) {
            return back()->withInput()->withErrors($placeError);
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
    public function edit(int $id, PricingService $pricing)
    {
        $market = Market::findOrFail($id);

        if (!$market->is_custom) {
            return redirect()
                ->route('manager-core.markets.index')
                ->with('error', 'Default markets cannot be edited. Disable them via the enable toggle if needed.');
        }

        return $this->form($market, false, $pricing);
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

        if ($placeError = self::placeError($validated, $market->key)) {
            return back()->withInput()->withErrors($placeError);
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
            'key'            => 'nullable|string|max:' . Market::KEY_MAX_LENGTH . '|regex:/^[a-z0-9-_]+$/',
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

        // A saved market keeps its key, whatever its length: the edit form
        // posts it back read-only and update() never stores it.
        if ($existing !== null) {
            unset($rules['key']);
        }

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
     * Fuzzwork and Janice price a fixed list of places and price anything
     * else at Jita 4-4 without saying so, so a market they would price that
     * way is refused, with what to do instead. Janice reads its place from
     * the slug, then the key; Fuzzwork from the key alone, and the five hub
     * keys are the seeded markets.
     *
     * @return array<string, string>|null the field and message, or null when fine
     */
    public static function placeError(array $data, string $key): ?array
    {
        $provider = $data['provider'] ?? null;

        if ($provider === 'fuzzwork' && !FuzzworkPriceProvider::tracksMarket($key)) {
            return ['provider' => 'Fuzzwork prices only the five trade hubs (Jita, Amarr, Dodixie, Hek and Rens), '
                . 'which are already on the list, and prices any other market at Jita 4-4. '
                . 'Pick MCPraisal (ESI) for this market, or Janice with the hub in the slug field.'];
        }

        if ($provider === 'janice'
            && !JanicePriceProvider::tracksMarket((string) ($data['provider_slug'] ?? ''))
            && !JanicePriceProvider::tracksMarket($key)) {
            return ['provider_slug' => 'Janice prices only the five trade hubs. Put jita, amarr, dodixie, hek or rens '
                . 'in the Provider Market Slug field, or Janice prices this market at Jita 4-4.'];
        }

        return null;
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

        return Market::fitKey(Str::slug($label));
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
     * The Add or Edit form, opened on the place the market (or the input a
     * refused save sent back) names, read from SeAT's tables.
     */
    protected function form(Market $market, bool $isNew, PricingService $pricing)
    {
        $places = $this->placeFinder($pricing, $isNew ? null : $market->id);

        $fields = [
            'market_type' => old('market_type', $market->market_type),
            'provider_slug' => old('provider_slug', $market->provider_slug),
            'structure_name' => old('structure_name', $market->structure_name),
            'system_id' => old('system_id', $market->system_id ?: ($market->system_ids[0] ?? null)),
            'system_name' => old('system_name', $market->system_name),
            'region_id' => old('region_id', $market->region_id),
        ];

        $named = !$isNew || old('provider_slug') || old('system_id') || old('region_id');
        $place = $named ? $places->resolve($fields) : null;

        return view('manager-core::markets.form', [
            'market' => $market,
            'isNew' => $isNew,
            'fields' => $fields,
            'place' => $place,
            'note' => $places->note(),
            'unplaced' => $places->unplaced(),
            'savedProvider' => $isNew ? null : $market->provider,
            'provider' => old('provider', $isNew ? ($place['provider'] ?? null) : $market->provider),
        ]);
    }

    protected function placeFinder(PricingService $pricing, ?int $editing): MarketPlaces
    {
        return new MarketPlaces($pricing, Market::all()->map(fn (Market $market) => $market->toArray())->all(), $editing);
    }

    /**
     * The plugins reading each market through a provider they name, by
     * market and provider: a provider override on a plugin's pricing
     * preference, or prices it subscribes to through that provider.
     *
     * @return array<string, array<string, string[]>>
     */
    protected function readersByProvider(): array
    {
        $readers = [];

        try {
            $overrides = DB::table('manager_core_pricing_preferences')
                ->whereNotNull('provider_override')
                ->where('provider_override', '<>', '')
                ->get(['plugin_key', 'market', 'provider_override']);

            foreach ($overrides as $row) {
                $readers[$row->market][strtolower(trim($row->provider_override))][] = self::pluginName($row->plugin_key);
            }

            $variants = DB::table('manager_core_variant_subscriptions')
                ->select('plugin_name', 'market', 'provider')
                ->distinct()
                ->get();

            foreach ($variants as $row) {
                $readers[$row->market][strtolower(trim($row->provider))][] = self::pluginName($row->plugin_name);
            }
        } catch (\Throwable $e) {
            Log::warning('[Manager Core] Markets: could not read which plugins name a provider: ' . $e->getMessage());
        }

        return $readers;
    }

    protected static function pluginName(string $key): string
    {
        $name = config("manager-core.bridge.compatible_plugins.{$key}.name");

        return is_string($name) && $name !== '' ? $name : ucwords(str_replace(['-', '_'], ' ', $key));
    }

    /** Directors in the ESI Key Pool, or null when the pool can't be read. */
    protected function keyPoolSize(): ?int
    {
        try {
            return EsiKeyHolder::where('enabled', true)->count();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Where a market is, in words: a Goonpraisal market by the system its
     * code names, otherwise the structure's type for one read through ESI,
     * then its systems and region by name.
     */
    protected function placeLine(Market $market, array $names): string
    {
        $code = $market->provider === 'goonpraisal' ? MarketPlaces::goonpraisalCode((string) $market->provider_slug) : null;
        if ($code !== null && isset($names['codes'][$code])) {
            return $names['codes'][$code];
        }

        $systemIds = array_filter(array_map('intval', (array) ($market->system_ids ?: [$market->system_id])));
        $systems = array_map(fn ($id) => $names['systems'][$id] ?? "system {$id}", $systemIds);
        if (empty($systems) && $market->system_name) {
            $systems = [$market->system_name];
        }

        $region = $market->region_id ? ($names['regions'][$market->region_id] ?? "region {$market->region_id}") : null;
        $type = $market->isStructureMarket() ? ($names['structures'][Market::structureIdFromSlug($market->provider_slug)] ?? null) : null;

        return implode(' · ', array_filter([$type, implode(', ', array_filter([implode(', ', $systems), $region]))]));
    }

    /**
     * Citadel markets by the provider that prices them. Goonpraisal's and
     * MCPraisal's groups always show, as the two ways to price a citadel;
     * any other only when a market uses it.
     *
     * @return array<string, array{label: string, intro: string, markets: \Illuminate\Support\Collection, enabled: int}>
     */
    protected function citadelGroups($citadels): array
    {
        $intros = [
            'goonpraisal' => 'Goonpraisal tracks these Imperium markets and prices them with no token needed. Enable the ones your alliance trades at.',
            'esi' => 'Read straight from the structure through the ESI Key Pool, as a director who can dock there with the esi-markets.structure_markets.v1 scope.',
            'janice' => 'Priced by Janice at the trade hub each one\'s slug names.',
            'fuzzwork' => 'Fuzzwork prices only the five trade hubs, so it prices these at Jita 4-4. Edit each one to a provider that prices its place.',
            'seat' => 'A SeAT price provider gives one price for every place, not the market\'s own.',
        ];

        $byProvider = $citadels->groupBy(fn (Market $market) => (string) $market->provider);
        $order = array_unique(array_merge(self::CITADEL_GROUPS, $byProvider->keys()->all()));
        $groups = [];

        foreach ($order as $provider) {
            $markets = $byProvider->get($provider, collect())->values();
            if ($markets->isEmpty() && !in_array($provider, ['goonpraisal', 'esi'], true)) {
                continue;
            }

            $groups[$provider] = [
                'label' => MarketPlaces::LABELS[$provider] ?? ($provider !== '' ? $provider : 'No provider'),
                'intro' => $intros[$provider] ?? "Manager Core has no provider called '{$provider}'. Edit these to one it has.",
                'markets' => $markets,
                'enabled' => $markets->where('is_enabled', true)->count(),
            ];
        }

        return $groups;
    }

    /**
     * One tile per provider for the top of the page: what it covers, whether
     * it is set up, and how many enabled markets it prices as their own.
     *
     * @return array<string, array{label: string, covers: string, state: string, status: string, link: ?string, markets: int}>
     */
    protected function providerTiles($markets, MarketPlaces $places, ?int $keyPool): array
    {
        $own = $markets->where('is_enabled', true)->countBy(fn (Market $market) => (string) $market->provider);
        $janice = $places->available('janice');
        $seat = $places->available('seat');

        $tiles = [
            'esi' => [
                'covers' => 'Any region\'s public market, and any structure a Key Pool director can dock at.',
                'state' => $keyPool === 0 ? 'setup' : 'ok',
                'status' => $keyPool === null ? 'Ready' : ($keyPool === 0 ? 'No directors in the Key Pool' : $keyPool . ' ' . ($keyPool === 1 ? 'director' : 'directors') . ' in the Key Pool'),
                'link' => route('manager-core.esi-key-pool.index'),
            ],
            'goonpraisal' => [
                'covers' => 'Jita, Amarr, Dodixie and ' . count(MarketPlaces::goonpraisalCodes()) . ' Imperium citadel markets.',
                'state' => 'ok',
                'status' => 'Ready, no token needed',
                'link' => null,
            ],
            'janice' => [
                'covers' => 'The five trade hubs, each at its station.',
                'state' => $janice ? 'ok' : 'setup',
                'status' => $janice ? 'API key set' : 'No API key',
                'link' => route('manager-core.settings') . '#pricing',
            ],
            'fuzzwork' => [
                'covers' => 'The five trade hubs, each at its station.',
                'state' => 'ok',
                'status' => 'Ready, no token needed',
                'link' => null,
            ],
            'seat' => [
                'covers' => 'One price for every place, from SeAT\'s own price provider.',
                'state' => $seat ? 'ok' : 'no',
                'status' => $seat ? 'Installed' : 'Not installed',
                'link' => null,
            ],
        ];

        foreach ($tiles as $provider => $tile) {
            $tiles[$provider] += ['label' => MarketPlaces::LABELS[$provider], 'markets' => (int) ($own[$provider] ?? 0)];
        }

        return $tiles;
    }
}
