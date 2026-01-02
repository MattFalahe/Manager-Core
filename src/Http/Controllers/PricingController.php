<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Services\PricingService;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\TypeSubscription;

class PricingController extends Controller
{
    /**
     * Pricing Service
     *
     * @var PricingService
     */
    protected $pricingService;

    /**
     * Constructor
     */
    public function __construct(PricingService $pricingService)
    {
        $this->pricingService = $pricingService;
    }

    /**
     * Display pricing dashboard
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $markets = config('manager-core.pricing.markets', []);
        $subscriptions = TypeSubscription::with([])
            ->select('market', \DB::raw('count(*) as count'))
            ->groupBy('market')
            ->get();

        $recentUpdates = MarketPrice::orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        return view('manager-core::pricing.index', compact('markets', 'subscriptions', 'recentUpdates'));
    }

    /**
     * Show pricing for a specific type
     *
     * @param int $typeId
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function showType($typeId, Request $request)
    {
        $market = $request->input('market', config('manager-core.pricing.default_market'));

        $prices = $this->pricingService->getPrice($typeId, $market);
        $trend = $this->pricingService->getTrend($typeId, $market, 7);

        // Get type info from SDE
        $type = \Seat\Eveapi\Models\Sde\InvType::find($typeId);

        if (!$type) {
            abort(404, 'Type not found');
        }

        return view('manager-core::pricing.type', compact('type', 'prices', 'trend', 'market'));
    }

    /**
     * Subscribe to type IDs for price tracking
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function subscribe(Request $request)
    {
        $request->validate([
            'plugin_name' => 'required|string',
            'type_ids' => 'required|array',
            'type_ids.*' => 'integer',
            'market' => 'nullable|string',
            'priority' => 'nullable|integer|min:1|max:10',
        ]);

        $this->pricingService->registerTypes(
            $request->input('plugin_name'),
            $request->input('type_ids'),
            $request->input('market', 'jita'),
            $request->input('priority', 1)
        );

        return response()->json([
            'success' => true,
            'message' => 'Subscribed to ' . count($request->input('type_ids')) . ' types',
        ]);
    }
}
