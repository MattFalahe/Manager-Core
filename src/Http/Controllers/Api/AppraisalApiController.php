<?php

namespace ManagerCore\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ManagerCore\Services\AppraisalService;

class AppraisalApiController extends ApiBaseController
{
    protected AppraisalService $appraisal;

    public function __construct(AppraisalService $appraisal)
    {
        $this->appraisal = $appraisal;
    }

    /**
     * POST /api/manager-core/v1/appraisals
     *
     * Create an appraisal from raw input.
     * Body: { "items": "1000 Tritanium\n500 Pyerite", "market": "jita", "price_percentage": 100 }
     */
    public function create(Request $request): JsonResponse
    {
        $rawInput = $request->input('items', '');
        $market = $request->input('market', \ManagerCore\Helpers\Settings::get('pricing.default_market', 'pricing.default_market', 'jita'));
        $pricePercentage = $request->input('price_percentage', 100);

        if (empty(trim($rawInput))) {
            return $this->error('items is required and must contain item text');
        }

        // Cap input size to prevent abuse (raw text body)
        if (strlen($rawInput) > 100000) {
            return $this->error('items input exceeds 100000 character limit', 413);
        }

        $token = $request->attributes->get('api_token');

        // M16: per-user appraisal create quota (per hour)
        if ($token && $token->user_id) {
            $quotaPerHour = (int) config('manager-core.api.appraisal_create_quota_per_hour', 200);
            $quotaKey = 'mc_appraisal_quota_' . $token->user_id;
            $current = (int) \Illuminate\Support\Facades\Cache::get($quotaKey, 0);
            if ($current >= $quotaPerHour) {
                return $this->error(
                    "Appraisal create quota exceeded. Limit is {$quotaPerHour} per hour per user.",
                    429
                );
            }
            // Increment by 1 second granularity, capped to 1 hour TTL
            \Illuminate\Support\Facades\Cache::put($quotaKey, $current + 1, 3600);
        }

        try {
            $appraisal = $this->appraisal->createAppraisal($rawInput, [
                'market' => $market,
                'price_percentage' => $pricePercentage,
                'user_id' => $token ? $token->user_id : null,
            ]);

            return $this->success([
                'appraisal_id' => $appraisal->appraisal_id,
                'market' => $appraisal->market,
                'kind' => $appraisal->kind,
                'total_buy' => (float) $appraisal->total_buy,
                'total_sell' => (float) $appraisal->total_sell,
                'total_volume' => (float) $appraisal->total_volume,
                'price_percentage' => (float) $appraisal->price_percentage,
                'item_count' => $appraisal->items->count(),
                'items' => $appraisal->items->map(function ($item) {
                    return [
                        'type_id' => $item->type_id,
                        'type_name' => $item->type_name,
                        'quantity' => $item->quantity,
                        'prices' => $item->prices,
                    ];
                }),
                'created_at' => $appraisal->created_at->toIso8601String(),
            ], 'Appraisal created', 201);

        } catch (\Throwable $e) {
            // M17: never leak raw exception messages to API consumers
            return $this->safeError($e, 'Could not create appraisal — see server logs for details', 422, 'appraisal.create');
        }
    }

    /**
     * GET /api/manager-core/v1/appraisals/{appraisalId}
     *
     * Get an appraisal by its public ID.
     */
    public function show(string $appraisalId, Request $request): JsonResponse
    {
        $privateToken = $request->query('private_token');

        $appraisal = $this->appraisal->getAppraisal($appraisalId, $privateToken);

        if (!$appraisal) {
            return $this->error('Appraisal not found', 404);
        }

        if ($appraisal->isExpired()) {
            return $this->error('Appraisal has expired', 410);
        }

        return $this->success([
            'appraisal_id' => $appraisal->appraisal_id,
            'market' => $appraisal->market,
            'kind' => $appraisal->kind,
            'total_buy' => (float) $appraisal->total_buy,
            'total_sell' => (float) $appraisal->total_sell,
            'total_volume' => (float) $appraisal->total_volume,
            'price_percentage' => (float) $appraisal->price_percentage,
            'item_count' => $appraisal->items->count(),
            'items' => $appraisal->items->map(function ($item) {
                return [
                    'type_id' => $item->type_id,
                    'type_name' => $item->type_name,
                    'quantity' => $item->quantity,
                    'prices' => $item->prices,
                ];
            }),
            'created_at' => $appraisal->created_at->toIso8601String(),
            'expires_at' => $appraisal->expires_at ? $appraisal->expires_at->toIso8601String() : null,
        ]);
    }
}
