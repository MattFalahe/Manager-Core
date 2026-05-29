<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Services\AppraisalService;
use ManagerCore\Models\Appraisal;

class AppraisalController extends Controller
{
    /**
     * Appraisal Service
     *
     * @var AppraisalService
     */
    protected $appraisalService;

    /**
     * Constructor
     */
    public function __construct(AppraisalService $appraisalService)
    {
        $this->appraisalService = $appraisalService;
    }

    /**
     * Display appraisal creation form
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        // Include disabled markets so operators can run on-demand appraisals
        // against the pre-seeded Goonpraisal citadel markets without first
        // having to flip the enable toggle. The on-demand fetch path doesn't
        // require enabled=true; only the scheduled cron does. We flag
        // disabled markets in the dropdown with a "(disabled)" suffix so
        // operators understand the scheduled refresh isn't covering it yet.
        $markets = \ManagerCore\Models\Market::getEffectiveMarkets(false);

        // Capture which markets are enabled for the dropdown suffix.
        try {
            $enabledMap = \ManagerCore\Models\Market::query()
                ->pluck('is_enabled', 'key')->toArray();
        } catch (\Throwable $e) {
            $enabledMap = [];
        }
        foreach ($markets as $key => &$cfg) {
            $cfg['is_enabled_for_polling'] = !array_key_exists($key, $enabledMap)
                || (bool) $enabledMap[$key];
        }
        unset($cfg);

        $recentAppraisals = Appraisal::where('user_id', auth()->user()->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Provider list for the per-appraisal dropdown. Each entry carries a
        // display label + an availability flag so the blade can grey out
        // providers that aren't configured (e.g. Janice without an API key).
        $providers = $this->buildProviderOptions();

        // Per-market provider routing — what the markets table says each
        // market routes to. Lets the blade show "Use this market's provider
        // (Goonpraisal)" instead of a now-defunct "Use global default" label.
        $marketProviders = [];
        foreach ($markets as $key => $market) {
            $marketProviders[$key] = $market['provider'] ?? 'fuzzwork';
        }

        // Compatibility data for the live "this provider supports this market"
        // badge AND the citadel-only-with-goonpraisal market filter:
        //   - $marketTypes: { key => 'hub' | 'citadel' | 'region' }
        //   - providers[X][supports]: 'hubs' | 'hubs_and_citadels' | 'jita_amarr'
        // The blade serialises these into JS — provider-change re-renders
        // the market dropdown showing only compatible markets, and
        // market-change snaps provider to a compatible one if the current
        // choice can't serve it.
        $marketTypes = $this->buildMarketTypeMap($markets);

        return view('manager-core::appraisal.index', compact(
            'markets',
            'recentAppraisals',
            'providers',
            'marketProviders',
            'marketTypes'
        ));
    }

    /**
     * Build the provider dropdown options. Returns a keyed array:
     *
     *   [provider_key => ['label' => string, 'available' => bool, 'note' => string|null, 'supports' => string]]
     *
     * 'supports' values (used by the blade JS to filter the market dropdown
     * when the provider changes — citadel markets disappear from the dropdown
     * when a non-Goonpraisal provider is selected, preventing the silent
     * Jita-fallback trap):
     *   - 'hubs_and_citadels' : tracks the 5 hub regions AND the 7
     *                            pre-seeded Goonpraisal nullsec citadels
     *                            (only Goonpraisal qualifies post the
     *                            2026-05-27 third-party provider pivot)
     *   - 'hubs'              : tracks only the five main trade hubs
     *                            (Jita/Amarr/Dodixie/Hek/Rens) — Fuzzwork,
     *                            MCPraisal/ESI, and SeAT-chained sub-providers
     *   - 'jita_amarr'        : Janice — currently only Jita + Amarr;
     *                            other hubs silently fall back to Jita
     *
     * Note: MCPraisal/ESI was historically marked 'all' because we used to
     * scrape /markets/structures/{id}/orders/ directly. The 2026-05-27 pivot
     * removed that path (CCP's pagination bug — pages 2..N return identical
     * content), so MCPraisal now only covers hubs via the working
     * /markets/{region_id}/orders/ endpoint.
     *
     * Availability mirrors each provider's own isAvailable() contract:
     * Janice needs an API key, SeAT needs prices-core installed,
     * MCPraisal + Fuzzwork + Goonpraisal are always on.
     */
    protected function buildProviderOptions(): array
    {
        $options = [
            'esi' => [
                'label' => 'MCPraisal (Manager Core ESI)',
                'available' => true,
                'supports' => 'hubs',
                'note' => 'Hub markets only; fresh CCP data via the ESI region endpoint',
            ],
            'fuzzwork' => [
                'label' => 'Fuzzwork',
                'available' => true,
                'supports' => 'hubs',
                'note' => 'Free community aggregator; hub markets only',
            ],
            'janice' => [
                'label' => 'Janice',
                'available' => false,
                'supports' => 'jita_amarr',
                'note' => 'Appraisal service; needs API key in Settings',
            ],
            'goonpraisal' => [
                'label' => 'Goonpraisal',
                'available' => true,
                'supports' => 'hubs_and_citadels',
                'note' => 'Covers Imperium nullsec hubs (C-J6MT, GB-6X5, etc.) plus Jita/Amarr/Dodixie. The only provider that can serve citadel markets in v1.0.0.',
            ],
            'seat' => [
                'label' => 'SeAT Price Provider',
                'available' => false,
                'supports' => 'hubs',
                'note' => 'Uses seat-prices-core if installed',
            ],
        ];

        // Mark "available" via each provider's own isAvailable() so the form
        // matches reality without hard-coding env checks here.
        try {
            $options['janice']['available'] = (new \ManagerCore\Services\PriceProviders\JanicePriceProvider())->isAvailable();
        } catch (\Throwable $e) { /* leave false */ }
        try {
            $options['seat']['available'] = (new \ManagerCore\Services\PriceProviders\SeatPriceProvider())->isAvailable();
        } catch (\Throwable $e) { /* leave false */ }

        return $options;
    }

    /**
     * Map each market key to a type so the JS compatibility check knows
     * what the provider has to support:
     *
     *   - 'hub'     — the five canonical trade hubs the third-party
     *                  providers track (Jita/Amarr/Dodixie/Hek/Rens)
     *   - 'citadel' — operator-added player citadel (MCPraisal-only)
     *   - 'region'  — operator-added regional market not in the hub list
     *                  (MCPraisal-only because no third-party provider
     *                  has region-scoped order books for custom regions)
     *
     * Pulls from the Market::getEffectiveMarkets() result so we honour
     * the same `market_type` discriminator MarketDataService uses when
     * dispatching hub vs citadel fetches.
     */
    protected function buildMarketTypeMap($markets): array
    {
        $knownHubs = ['jita', 'amarr', 'dodixie', 'hek', 'rens'];
        $map = [];
        foreach ($markets as $key => $market) {
            $mt = $market['market_type'] ?? null;
            if ($mt === \ManagerCore\Models\Market::TYPE_CITADEL) {
                $map[$key] = 'citadel';
            } elseif (in_array(strtolower((string) $key), $knownHubs, true)) {
                $map[$key] = 'hub';
            } else {
                // Hub-typed market but not one of the five major hubs that
                // third-party aggregators cover — treat as a custom region.
                $map[$key] = 'region';
            }
        }
        return $map;
    }

    /**
     * Create a new appraisal
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function create(Request $request)
    {
        $request->validate([
            'raw_input' => 'required|string|max:100000',
            'market' => 'required|string',
            'price_percentage' => 'nullable|numeric|min:0|max:200',
            'is_private' => 'nullable|boolean',
            // Optional. Null/missing means "use the global default provider".
            'price_provider' => 'nullable|string|in:esi,janice,fuzzwork,goonpraisal,seat',
        ]);

        try {
            $options = [
                'market' => $request->input('market'),
                'price_percentage' => $request->input('price_percentage', 100),
                'user_id' => auth()->user()->id,
                'is_private' => $request->boolean('is_private'),
                'price_provider' => $request->input('price_provider') ?: null,
            ];

            $appraisal = $this->appraisalService->createAppraisal(
                $request->input('raw_input'),
                $options
            );

            return redirect()->route('manager-core.appraisal.show', $appraisal->appraisal_id)
                ->with('success', 'Appraisal created successfully');

        } catch (\Exception $e) {
            return back()->withInput()
                ->with('error', 'Failed to create appraisal: ' . $e->getMessage());
        }
    }

    /**
     * Show an appraisal
     *
     * @param string $appraisalId
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function show($appraisalId, Request $request)
    {
        $privateToken = $request->input('token');

        $appraisal = $this->appraisalService->getAppraisal($appraisalId, $privateToken);

        if (!$appraisal) {
            abort(404, 'Appraisal not found or access denied');
        }

        return view('manager-core::appraisal.show', compact('appraisal'));
    }

    /**
     * Delete an appraisal
     *
     * @param string $appraisalId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function delete($appraisalId)
    {
        $appraisal = Appraisal::where('appraisal_id', $appraisalId)->first();

        if (!$appraisal) {
            abort(404);
        }

        // Check ownership
        if ($appraisal->user_id !== auth()->user()->id && !auth()->user()->can('global.superuser')) {
            abort(403);
        }

        $appraisal->delete();

        return redirect()->route('manager-core.appraisal.index')
            ->with('success', 'Appraisal deleted successfully');
    }
}
