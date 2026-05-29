<?php

namespace ManagerCore\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ManagerCore\Services\PricingService;
use ManagerCore\Services\SdeService;

class PriceApiController extends ApiBaseController
{
    protected PricingService $pricing;
    protected SdeService $sde;

    public function __construct(PricingService $pricing, SdeService $sde)
    {
        $this->pricing = $pricing;
        $this->sde = $sde;
    }

    /**
     * GET /api/manager-core/v1/prices/{typeId}
     *
     * Get price for a single type ID.
     */
    public function getPrice(int $typeId, Request $request): JsonResponse
    {
        // L20 ride-along: plausibility check on type ID
        if ($typeId < 1 || $typeId > 100000000) {
            return $this->error("type_id out of plausible range", 400);
        }

        $market = $request->query('market', \ManagerCore\Helpers\Settings::get('pricing.default_market', 'pricing.default_market', 'jita'));
        $priceType = $request->query('price_type', 'both');

        // M15: use the always-keyed getPrices() so the API response shape is
        // identical to the batch endpoint. Drop dependency on the legacy
        // singular-vs-plural shape collapse in PricingService::getPrice.
        $pricesMap = $this->pricing->getPrices([$typeId], $market, $priceType);
        $prices = $pricesMap[$typeId] ?? null;

        if (!$prices) {
            return $this->error("No price data for type ID {$typeId} in {$market}", 404);
        }

        $typeName = $this->sde->typeName($typeId);

        return $this->success([
            'type_id' => $typeId,
            'type_name' => $typeName,
            'market' => $market,
            'prices' => $prices,
        ]);
    }

    /**
     * POST /api/manager-core/v1/prices/batch
     *
     * Get prices for multiple type IDs.
     * Body: { "type_ids": [34, 35, 36], "market": "jita", "price_type": "both" }
     */
    public function getPrices(Request $request): JsonResponse
    {
        $typeIds = $request->input('type_ids', []);
        $market = $request->input('market', \ManagerCore\Helpers\Settings::get('pricing.default_market', 'pricing.default_market', 'jita'));
        $priceType = $request->input('price_type', 'both');

        if (empty($typeIds) || !is_array($typeIds)) {
            return $this->error('type_ids is required and must be a non-empty array');
        }

        if (count($typeIds) > 500) {
            return $this->error('Maximum 500 type IDs per request');
        }

        $typeIds = array_values(array_filter(array_map('intval', $typeIds), fn($id) => $id >= 1 && $id <= 100000000));

        if (empty($typeIds)) {
            return $this->error('type_ids contained no valid IDs after filtering');
        }

        $prices = $this->pricing->getPrices($typeIds, $market, $priceType);
        $typeNames = $this->sde->typeNames($typeIds);

        // Enrich with type names
        $results = [];
        foreach ($typeIds as $typeId) {
            $results[$typeId] = [
                'type_id' => $typeId,
                'type_name' => $typeNames[$typeId] ?? null,
                'prices' => $prices[$typeId] ?? null,
            ];
        }

        return $this->success([
            'market' => $market,
            'price_type' => $priceType,
            'items' => $results,
            'count' => count($results),
        ]);
    }

    /**
     * GET /api/manager-core/v1/prices/{typeId}/trend
     *
     * Get price trend for a type ID.
     */
    public function getTrend(int $typeId, Request $request): JsonResponse
    {
        $market = $request->query('market', \ManagerCore\Helpers\Settings::get('pricing.default_market', 'pricing.default_market', 'jita'));
        $days = (int) $request->query('days', 7);

        if ($days < 1 || $days > 90) {
            return $this->error('Days must be between 1 and 90');
        }

        $trend = $this->pricing->getTrend($typeId, $market, $days);
        $typeName = $this->sde->typeName($typeId);

        return $this->success([
            'type_id' => $typeId,
            'type_name' => $typeName,
            'market' => $market,
            'days' => $days,
            'trend' => $trend,
        ]);
    }
}
