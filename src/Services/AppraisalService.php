<?php

namespace ManagerCore\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
     * Get the parser service instance
     *
     * @return ParserService
     */
    public function getParserService(): ParserService
    {
        return $this->parser;
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

            // Enforce max item count before expensive SDE validation
            $maxItems = config('manager-core.appraisal.max_items', 1000);
            if (count($parseResult['items']) > $maxItems) {
                Log::warning("[Manager Core] Item count exceeds maximum", ['count' => count($parseResult['items']), 'max' => $maxItems]);
                throw new \Exception("Too many items. Maximum allowed is {$maxItems}, but input contains " . count($parseResult['items']) . " items.");
            }

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

            // Get market and configuration. H8: settings UI overrides config.
            $market = $options['market']
                ?? \ManagerCore\Helpers\Settings::get('pricing.default_market', 'pricing.default_market', 'jita');
            $pricePercentage = $options['price_percentage'] ?? config('manager-core.appraisal.default_percentage', 100);
            $userId = $options['user_id'] ?? null;
            $isPrivate = $options['is_private'] ?? false;

            // Per-appraisal price provider. Null means "use the global default".
            // Validated against the canonical key set so a stale form post
            // can't smuggle an unknown provider string into the table.
            $allowedProviders = ['esi', 'janice', 'fuzzwork', 'goonpraisal', 'seat'];
            $priceProvider = $options['price_provider'] ?? null;
            if ($priceProvider !== null && !in_array($priceProvider, $allowedProviders, true)) {
                Log::warning("[Manager Core] Unknown price_provider '{$priceProvider}' ignored; falling back to global default");
                $priceProvider = null;
            }

            // Create appraisal record
            $appraisal = new Appraisal();
            $appraisal->appraisal_id = $this->generateAppraisalId();
            $appraisal->user_id = $userId;
            $appraisal->market = $market;
            $appraisal->kind = $parseResult['parser'];
            $appraisal->raw_input = $rawInput;
            $appraisal->price_percentage = $pricePercentage;
            $appraisal->is_private = $isPrivate;
            $appraisal->parser_info = ['parser' => $parseResult['parser']];

            // Only persist when the column exists (migration 000021). Guarded
            // so older installs upgrading mid-flight don't blow up on the
            // first appraisal after pull.
            if (Schema::hasColumn('manager_core_appraisals', 'price_provider')) {
                $appraisal->price_provider = $priceProvider;
            }

            // Combine unparsed lines and invalid items
            $appraisal->unparsed_lines = [
                'unparsed_lines' => $parseResult['unparsed'],
                'invalid_items' => $validationResult['invalid'] ?? []
            ];

            if ($isPrivate) {
                $appraisal->private_token = Str::random(32);
            }

            // Set expiration. H8: settings UI overrides config.
            $retentionDays = (int) \ManagerCore\Helpers\Settings::get(
                'appraisal.retention_days',
                'appraisal.retention_days',
                30
            );
            if ($retentionDays > 0) {
                $appraisal->expires_at = now()->addDays($retentionDays);
            }

            $appraisal->save();
            Log::info("[Manager Core] Saved appraisal record", ['appraisal_id' => $appraisal->appraisal_id]);

            // Auto-subscribe to these type IDs for future price updates
            $this->subscribeToTypes($items, $market);

            // Fetch prices immediately for this appraisal. When the operator
            // picked a per-appraisal provider, route through it instead of the
            // global default so the Janice / Fuzzwork / MCPraisal selection
            // they made actually drives the fetch.
            $typeIds = array_column($items, 'type_id');
            Log::info("[Manager Core] Fetching prices for {$market} for " . count($typeIds) . " items"
                . ($priceProvider ? " via {$priceProvider}" : ''));
            $this->pricing->updatePrices($market, $typeIds, $priceProvider);

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

        // Batch-fetch all type info from SDE to avoid N+1 queries
        $typeIds = array_filter(array_column($items, 'type_id'));
        $typesMap = InvType::with('group')
            ->whereIn('typeID', $typeIds)
            ->get()
            ->keyBy('typeID');

        // Batch-fetch all prices at once
        $prices = $this->pricing->getPrice($typeIds, $appraisal->market);

        // When getPrice returns a single item result (1 type), normalize to keyed array
        if (count($typeIds) === 1) {
            $prices = [$typeIds[0] => $prices];
        }

        // Jita fallback prep: when the configured market is a regional or
        // citadel market (e.g. a nullsec local hub) and an item has no orders
        // there, we fall back to Jita so the appraisal still shows a real
        // number. Each item row is tagged with `prices.source` so the show
        // view can label fallback rows clearly — not deceptive, the operator
        // sees exactly what came from where.
        $jitaPrices = null;
        if ($appraisal->market !== 'jita') {
            try {
                // Make sure Jita prices are reasonably fresh for these types
                $this->pricing->updatePrices('jita', $typeIds);
                $jitaPrices = $this->pricing->getPrice($typeIds, 'jita');
                if (count($typeIds) === 1) {
                    $jitaPrices = [$typeIds[0] => $jitaPrices];
                }
            } catch (\Throwable $e) {
                Log::warning("[Manager Core] Jita fallback price-fetch failed; will not apply fallback", ['error' => $e->getMessage()]);
                $jitaPrices = null;
            }
        }
        $fallbackCount = 0;

        foreach ($items as $item) {
            try {
                if (!isset($item['type_id']) || !$item['type_id']) {
                    Log::warning("[Manager Core] Skipping item without type_id", ['item' => $item]);
                    continue;
                }

                // Get type info from pre-fetched batch
                $type = $typesMap[$item['type_id']] ?? null;
                if (!$type) {
                    Log::warning("[Manager Core] Type ID {$item['type_id']} not found in SDE");
                    $errorCount++;
                    continue;
                }

                // Get prices from pre-fetched batch
                $itemPrices = $prices[$item['type_id']] ?? null;

                if (!$itemPrices) {
                    Log::warning("[Manager Core] No price data for type_id: {$item['type_id']}");
                    $itemPrices = ['buy' => null, 'sell' => null];
                }

                // Jita fallback: if both buy and sell are zero in the
                // configured market AND Jita has a price for this type, swap
                // in the Jita data and flag the row as `jita_fallback`. The
                // operator sees a meaningful appraisal even when the local
                // hub has no orders for the item.
                $source = 'configured';
                $hasBuy = isset($itemPrices['buy']['max']) && (float) $itemPrices['buy']['max'] > 0;
                $hasSell = isset($itemPrices['sell']['min']) && (float) $itemPrices['sell']['min'] > 0;
                if (!$hasBuy && !$hasSell && $jitaPrices !== null) {
                    $jp = $jitaPrices[$item['type_id']] ?? null;
                    $jpHasBuy = is_array($jp) && isset($jp['buy']['max']) && (float) $jp['buy']['max'] > 0;
                    $jpHasSell = is_array($jp) && isset($jp['sell']['min']) && (float) $jp['sell']['min'] > 0;
                    if ($jpHasBuy || $jpHasSell) {
                        $itemPrices = $jp;
                        $source = 'jita_fallback';
                        $fallbackCount++;
                    }
                }

                // Calculate totals - safely handle null buy/sell arrays
                $buyPrice = isset($itemPrices['buy']['max']) ? (float) $itemPrices['buy']['max'] : 0;
                $sellPrice = isset($itemPrices['sell']['min']) ? (float) $itemPrices['sell']['min'] : 0;

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
                $appraisalItem->quantity = $quantity;

                // Set group/category if the columns exist (added by later migration)
                if (Schema::hasColumn('manager_core_appraisal_items', 'group_id')) {
                    $appraisalItem->group_id = $type->groupID ?? null;
                    $appraisalItem->category_id = $type->group ? $type->group->categoryID : null;
                }
                $appraisalItem->type_volume = $typeVolume;
                $appraisalItem->total_volume = $typeVolume * $quantity;
                $appraisalItem->prices = [
                    'buy' => $itemPrices['buy'],
                    'sell' => $itemPrices['sell'],
                    'buy_price' => $buyPrice,
                    'sell_price' => $sellPrice,
                    'buy_total' => $buyPrice * $quantity,
                    'sell_total' => $sellPrice * $quantity,
                    'source' => $source,   // 'configured' or 'jita_fallback'
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
                Log::error("[Manager Core] Failed to create appraisal item: " . $e->getMessage(), [
                    'appraisal_id' => $appraisal->appraisal_id,
                    'type_id' => $item['type_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $errorCount++;

                // Re-throw the first error so the user sees why items failed
                if ($itemCount === 0 && $errorCount === 1) {
                    throw new \Exception("Failed to save appraisal item (type {$item['type_id']}): " . $e->getMessage());
                }
            }
        }

        Log::info("[Manager Core] Populated appraisal items", [
            'appraisal_id' => $appraisal->appraisal_id,
            'success_count' => $itemCount,
            'error_count' => $errorCount
        ]);

        // If ALL items failed, throw so the user gets feedback
        if ($itemCount === 0 && $errorCount > 0) {
            throw new \Exception("All {$errorCount} items failed to save. Check server logs for details.");
        }

        // Update appraisal totals + record Jita-fallback count so the show
        // view can render a banner explaining which rows used the fallback.
        $appraisal->total_buy = $totalBuy;
        $appraisal->total_sell = $totalSell;
        $appraisal->total_volume = $totalVolume;
        if ($fallbackCount > 0) {
            $parserInfo = $appraisal->parser_info ?? [];
            if (!is_array($parserInfo)) {
                $parserInfo = [];
            }
            $parserInfo['jita_fallback_count'] = $fallbackCount;
            $appraisal->parser_info = $parserInfo;
        }
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
            $this->pricing->registerTypes('appraisal', $typeIds, $market, 5, false);
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
