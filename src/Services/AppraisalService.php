<?php

namespace ManagerCore\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\Appraisal;
use ManagerCore\Models\AppraisalItem;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * AppraisalService - Main service for creating and managing appraisals
 *
 * Based on go-evepraisal's appraisal system
 */
class AppraisalService
{
    /**
     * Parser Service
     *
     * @var ParserService
     */
    protected $parser;

    /**
     * Pricing Service
     *
     * @var PricingService
     */
    protected $pricing;

    /**
     * Constructor
     */
    public function __construct(ParserService $parser, PricingService $pricing)
    {
        $this->parser = $parser;
        $this->pricing = $pricing;
    }

    /**
     * Create an appraisal from raw input text
     *
     * @param string $rawInput
     * @param array $options
     * @return Appraisal
     */
    public function createAppraisal($rawInput, array $options = [])
    {
        try {
            Log::info("[Manager Core] Creating appraisal", ['market' => $options['market'] ?? 'default', 'user_id' => $options['user_id'] ?? null]);

            // Parse the input
            $parseResult = $this->parser->parse($rawInput);

            if (!$parseResult['success'] || empty($parseResult['items'])) {
                Log::warning("[Manager Core] No valid items found in input", ['input_length' => strlen($rawInput)]);
                throw new \Exception('No valid items found in input');
            }

            Log::info("[Manager Core] Parsed input successfully", ['item_count' => count($parseResult['items']), 'parser' => $parseResult['parser']]);

            // Validate items against SDE
            $validationResult = $this->parser->validateItems($parseResult['items']);

            if (empty($validationResult['valid'])) {
                Log::error("[Manager Core] No valid items found after validation");
                throw new \Exception('Could not resolve any item names. Please check spelling and try again.');
            }

            $items = $validationResult['valid'];

            // Log invalid items for debugging
            if (!empty($validationResult['invalid'])) {
                Log::warning("[Manager Core] Found invalid items", [
                    'invalid_count' => count($validationResult['invalid']),
                    'invalid_items' => array_column($validationResult['invalid'], 'name')
                ]);
            }

            Log::info("[Manager Core] Validated items", [
                'valid_count' => count($items),
                'invalid_count' => count($validationResult['invalid'])
            ]);

            // Get market and configuration
            $market = $options['market'] ?? config('manager-core.pricing.default_market', 'jita');
            $pricePercentage = $options['price_percentage'] ?? config('manager-core.appraisal.default_percentage', 100);
            $userId = $options['user_id'] ?? null;
            $isPrivate = $options['is_private'] ?? false;

            // Create appraisal record
            $appraisal = new Appraisal();
            $appraisal->appraisal_id = $this->generateAppraisalId();
            $appraisal->user_id = $userId;
            $appraisal->market = $market;
            $appraisal->kind = $parseResult['parser'];
            $appraisal->raw_input = $rawInput;
            $appraisal->price_percentage = $pricePercentage;
            $appraisal->is_private = $isPrivate;
            $appraisal->parser_info = json_encode(['parser' => $parseResult['parser']]);

            // Combine unparsed lines and invalid items
            $unparsedData = [
                'unparsed_lines' => $parseResult['unparsed'],
                'invalid_items' => $validationResult['invalid'] ?? []
            ];
            $appraisal->unparsed_lines = json_encode($unparsedData);

            if ($isPrivate) {
                $appraisal->private_token = Str::random(32);
            }

            // Set expiration
            $retentionDays = config('manager-core.appraisal.retention_days', 30);
            if ($retentionDays > 0) {
                $appraisal->expires_at = now()->addDays($retentionDays);
            }

            $appraisal->save();
            Log::info("[Manager Core] Saved appraisal record", ['appraisal_id' => $appraisal->appraisal_id]);

            // Auto-subscribe to these type IDs for future price updates
            $this->subscribeToTypes($items, $market);

            // Fetch prices immediately for this appraisal
            $typeIds = array_column($items, 'type_id');
            Log::info("[Manager Core] Fetching prices for {$market} for " . count($typeIds) . " items");
            $this->pricing->updatePrices($market, $typeIds);

            // Create appraisal items and calculate totals
            $this->populateAppraisalItems($appraisal, $items);

            Log::info("[Manager Core] Created appraisal {$appraisal->appraisal_id} with {$appraisal->items->count()} items");

            return $appraisal->fresh(['items']);

        } catch (\Exception $e) {
            Log::error("[Manager Core] Failed to create appraisal", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'market' => $options['market'] ?? null,
                'user_id' => $options['user_id'] ?? null
            ]);
            throw $e;
        }
    }

    /**
     * Populate appraisal with items and pricing data
     *
     * @param Appraisal $appraisal
     * @param array $items
     * @return void
     */
    protected function populateAppraisalItems(Appraisal $appraisal, array $items)
    {
        $totalBuy = 0;
        $totalSell = 0;
        $totalVolume = 0;
        $itemCount = 0;
        $errorCount = 0;

        Log::info("[Manager Core] Populating appraisal items", ['appraisal_id' => $appraisal->appraisal_id, 'item_count' => count($items)]);

        foreach ($items as $item) {
            try {
                if (!isset($item['type_id']) || !$item['type_id']) {
                    Log::warning("[Manager Core] Skipping item without type_id", ['item' => $item]);
                    continue;
                }

                // Get type info from SDE
                $type = InvType::find($item['type_id']);
                if (!$type) {
                    Log::warning("[Manager Core] Type ID {$item['type_id']} not found in SDE");
                    $errorCount++;
                    continue;
                }

                // Get prices
                $prices = $this->pricing->getPrice($item['type_id'], $appraisal->market);

                if (!$prices) {
                    Log::warning("[Manager Core] No price data for type_id: {$item['type_id']}");
                    $prices = ['buy' => null, 'sell' => null];
                }

                // Calculate totals
                $buyPrice = $prices['buy']['max'] ?? 0;
                $sellPrice = $prices['sell']['min'] ?? 0;

                // Apply price percentage modifier
                if ($appraisal->price_percentage != 100) {
                    $buyPrice *= ($appraisal->price_percentage / 100);
                    $sellPrice *= ($appraisal->price_percentage / 100);
                }

                $quantity = $item['quantity'];
                $typeVolume = $type->packaged_volume ?? $type->volume ?? 0;

                $totalBuy += $buyPrice * $quantity;
                $totalSell += $sellPrice * $quantity;
                $totalVolume += $typeVolume * $quantity;

                // Create appraisal item
                $appraisalItem = new AppraisalItem();
                $appraisalItem->appraisal_id = $appraisal->id;
                $appraisalItem->type_id = $item['type_id'];
                $appraisalItem->type_name = $type->typeName;
                $appraisalItem->group_id = $type->groupID ?? null;
                $appraisalItem->category_id = $type->group->categoryID ?? null;
                $appraisalItem->quantity = $quantity;
                $appraisalItem->type_volume = $typeVolume;
                $appraisalItem->total_volume = $typeVolume * $quantity;
                $appraisalItem->prices = [
                    'buy' => $prices['buy'],
                    'sell' => $prices['sell'],
                    'buy_price' => $buyPrice,
                    'sell_price' => $sellPrice,
                    'buy_total' => $buyPrice * $quantity,
                    'sell_total' => $sellPrice * $quantity,
                ];

                // Set metadata
                if (isset($item['is_bpc']) && $item['is_bpc']) {
                    $appraisalItem->is_bpc = true;
                    $appraisalItem->bpc_runs = $item['bpc_runs'] ?? 1;
                }

                if (isset($item['is_fitted'])) {
                    $appraisalItem->is_fitted = $item['is_fitted'];
                }

                if (isset($item['location'])) {
                    $appraisalItem->location = $item['location'];
                }

                $appraisalItem->save();
                $itemCount++;

            } catch (\Exception $e) {
                Log::error("[Manager Core] Failed to create appraisal item", [
                    'appraisal_id' => $appraisal->appraisal_id,
                    'type_id' => $item['type_id'] ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $errorCount++;
            }
        }

        Log::info("[Manager Core] Populated appraisal items", [
            'appraisal_id' => $appraisal->appraisal_id,
            'success_count' => $itemCount,
            'error_count' => $errorCount
        ]);

        // Update appraisal totals
        $appraisal->total_buy = $totalBuy;
        $appraisal->total_sell = $totalSell;
        $appraisal->total_volume = $totalVolume;
        $appraisal->save();

        Log::info("[Manager Core] Updated appraisal totals", [
            'appraisal_id' => $appraisal->appraisal_id,
            'total_buy' => $totalBuy,
            'total_sell' => $totalSell,
            'total_volume' => $totalVolume
        ]);
    }


    /**
     * Generate a unique appraisal ID
     *
     * @return string
     */
    protected function generateAppraisalId()
    {
        do {
            $id = Str::random(8);
        } while (Appraisal::where('appraisal_id', $id)->exists());

        return $id;
    }

    /**
     * Get appraisal by public ID
     *
     * @param string $appraisalId
     * @param string|null $privateToken
     * @return Appraisal|null
     */
    public function getAppraisal($appraisalId, $privateToken = null)
    {
        $query = Appraisal::where('appraisal_id', $appraisalId);

        $appraisal = $query->first();

        if (!$appraisal) {
            return null;
        }

        // Check private access
        if ($appraisal->is_private && $appraisal->private_token !== $privateToken) {
            return null;
        }

        return $appraisal->load('items');
    }

    /**
     * Subscribe to types for automatic price updates
     *
     * @param array $items
     * @param string $market
     * @return void
     */
    protected function subscribeToTypes(array $items, string $market)
    {
        $typeIds = array_column($items, 'type_id');

        if (empty($typeIds)) {
            return;
        }

        try {
            $this->pricing->registerTypes('appraisal', $typeIds, $market, 5);
            Log::info("[Manager Core] Auto-subscribed to " . count($typeIds) . " types for market: {$market}");
        } catch (\Exception $e) {
            Log::warning("[Manager Core] Failed to auto-subscribe types", [
                'error' => $e->getMessage(),
                'type_ids' => $typeIds,
                'market' => $market
            ]);
        }
    }

    /**
     * Delete expired appraisals
     *
     * @return int Number of deleted appraisals
     */
    public function deleteExpiredAppraisals()
    {
        $count = Appraisal::where('expires_at', '<', now())->delete();

        if ($count > 0) {
            Log::info("[Manager Core] Deleted {$count} expired appraisals");
        }

        return $count;
    }
}
