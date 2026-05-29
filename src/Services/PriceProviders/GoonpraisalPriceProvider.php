<?php

namespace ManagerCore\Services\PriceProviders;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\PriceHistory;
use ManagerCore\Models\Setting;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * Goonpraisal appraisal-service price provider.
 *
 * Goonpraisal (appraise.gnf.lt) is a deploy of evepraisal that has
 * accumulated its own dataset of nullsec citadel orders over years.
 * It serves correct prices for major Imperium hubs (C-J6MT, GB-6X5,
 * UALX-3, HY-RWO, O4T-Z5, R-ARKN, GM-0K7) plus the standard Jita /
 * Amarr / Dodixie hubs and a "Universe" fallback that returns CCP's
 * adjusted_price.
 *
 * Per Goonpraisal's API docs (https://appraise.gnf.lt/api-docs):
 *
 *   1. Use ?persist=no when doing programmatic bulk lookups so we
 *      don't pollute their appraisal history database. We treat this
 *      as mandatory in this provider — we're never asking for a
 *      persisted appraisal.
 *
 *   2. Send a clearly-identifying User-Agent that includes contact
 *      info. We construct ours from the plugin name + version + the
 *      operator-configured contact email (defaults to the plugin
 *      maintainer's email so installs work out of the box without
 *      configuration).
 *
 *   3. Don't hammer the service. We pace per-batch HTTP requests with
 *      a small sleep between batches.
 *
 * POST shape (multipart/form-data):
 *   raw_textarea=<newline-separated item names>
 *
 * Response shape (JSON):
 *   {
 *     "appraisal": {
 *       "items": [
 *         {
 *           "typeID": 13956,
 *           "name": "True Sansha Large Armor Repairer",
 *           "quantity": 1,
 *           "prices": {
 *             "buy":  { "max": ..., "min": ..., "avg": ..., "median": ..., "percentile": ..., "volume": ..., "order_count": ... },
 *             "sell": { "min": ..., "max": ..., "avg": ..., "median": ..., "percentile": ..., "volume": ..., "order_count": ... }
 *           }
 *         }, ...
 *       ]
 *     }
 *   }
 */
class GoonpraisalPriceProvider implements PriceProviderInterface
{
    private const BASE_URL = 'https://appraise.gnf.lt';
    private const ENDPOINT = '/appraisal.json';

    private const HTTP_TIMEOUT = 30;
    private const BATCH_SIZE = 100;
    private const INTER_BATCH_PAUSE_USEC = 750000;  // 0.75s between batches — courteous

    private const DEFAULT_CONTACT_EMAIL = 'mattfalahe@gmail.com';

    /**
     * Valid Goonpraisal market slugs. Verified 2026-05-27 by inspecting
     * the <option value=> attributes on https://appraise.gnf.lt/.
     *
     * Pattern:
     *   - Hub markets use lowercase region/system names ('jita', 'amarr',
     *     'dodixie', 'universe')
     *   - Citadel markets use the structure system code in UPPERCASE
     *     with the hyphen ('C-J6MT', 'UALX-3', 'GB-6X5', etc.) — NOT
     *     the region or constellation name as I'd initially guessed.
     *
     * The MC seed migration (000009) pre-creates citadel markets with
     * the correct provider_slug already set. This list is the fallback
     * lookup for ad-hoc operator-configured markets that pass the slug
     * directly as the MC market key.
     */
    private const SUPPORTED_SLUGS = [
        'jita',
        'amarr',
        'dodixie',
        'universe',
        'C-J6MT',
        'UALX-3',
        'HY-RWO',
        'O4T-Z5',
        'R-ARKN',
        'GB-6X5',
        'GM-0K7',
    ];

    public function getPrices(array $typeIds, string $market, bool $persist = true): array
    {
        $out = array_fill_keys(array_map('intval', $typeIds), null);

        if (empty($typeIds)) {
            return $out;
        }

        // Resolve the Goonpraisal slug for this market. Prefer the
        // explicit provider_slug from manager_core_markets; fall back
        // to the market key itself only if it's a known Goonpraisal
        // slug (handles operator-configured hub markets that pass
        // 'jita' directly).
        $slug = $this->resolveSlug($market);
        if ($slug === null) {
            Log::warning("[Manager Core] Goonpraisal: market '{$market}' has no Goonpraisal slug configured; provider returning no data");
            return $out;
        }

        $persistTag = $persist ? '' : ' (no-persist override mode)';
        Log::info("[Manager Core] Goonpraisal: fetching " . count($typeIds) . " types for market '{$market}' (slug={$slug}){$persistTag}");

        // Pre-resolve type_id → typeName via SDE so we can construct the
        // raw_textarea body Goonpraisal expects. invTypes has typeID +
        // typeName for every published item.
        $typeNames = InvType::whereIn('typeID', $typeIds)
            ->pluck('typeName', 'typeID')
            ->toArray();

        if (empty($typeNames)) {
            Log::warning("[Manager Core] Goonpraisal: no SDE rows found for the requested type_ids; nothing to fetch");
            return $out;
        }

        $userAgent = $this->buildUserAgent();
        $url = self::BASE_URL . self::ENDPOINT . '?market=' . urlencode($slug) . '&persist=no';
        $updatedCount = 0;

        foreach (array_chunk($typeNames, self::BATCH_SIZE, true) as $batchIndex => $batch) {
            try {
                $body = implode("\n", array_values($batch));

                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->withHeaders([
                        'User-Agent' => $userAgent,
                        'Accept'     => 'application/json',
                    ])
                    ->asForm()
                    ->post($url, [
                        'raw_textarea' => $body,
                    ]);

                if (!$response->successful()) {
                    Log::warning("[Manager Core] Goonpraisal batch {$batchIndex} HTTP " . $response->status() . ": " . substr($response->body(), 0, 200));
                    continue;
                }

                $data = $response->json();
                if (!is_array($data) || !isset($data['appraisal']['items'])) {
                    Log::warning("[Manager Core] Goonpraisal batch {$batchIndex} response missing appraisal.items");
                    continue;
                }

                foreach ($data['appraisal']['items'] as $item) {
                    $typeId = (int) ($item['typeID'] ?? 0);
                    if ($typeId === 0 || !isset($typeNames[$typeId])) {
                        continue;
                    }
                    // Build in-memory stats for the return map (required
                    // by Option B no-persist path) BEFORE optionally
                    // persisting.
                    $out[$typeId] = $this->buildStatsFromGoonpraisalItem($item);
                    if ($persist) {
                        $this->storeFromGoonpraisalItem($typeId, $market, $item);
                    }
                    $updatedCount++;
                }
            } catch (\Throwable $e) {
                Log::error("[Manager Core] Goonpraisal batch {$batchIndex} threw: " . $e->getMessage());
                continue;
            }

            if ($batchIndex < count($typeNames) - 1) {
                usleep(self::INTER_BATCH_PAUSE_USEC);
            }
        }

        Log::info("[Manager Core] Goonpraisal: " . ($persist ? 'updated' : 'fetched') . " {$updatedCount}/" . count($typeNames) . " types in market '{$market}'");
        return $out;
    }

    /**
     * Build the in-memory stats map for one Goonpraisal item response.
     * Mirrors saveSide's column-mapping decisions.
     *
     * @return array{buy: ?array, sell: ?array}
     */
    protected function buildStatsFromGoonpraisalItem(array $item): array
    {
        $prices = is_array($item['prices'] ?? null) ? $item['prices'] : [];
        $buy  = is_array($prices['buy']  ?? null) ? $prices['buy']  : null;
        $sell = is_array($prices['sell'] ?? null) ? $prices['sell'] : null;

        $buildSide = function (?array $bucket): ?array {
            if (!is_array($bucket)) return null;
            $min = (float) ($bucket['min'] ?? 0);
            $max = (float) ($bucket['max'] ?? 0);
            $avg = (float) ($bucket['avg'] ?? 0);
            if ($min == 0.0 && $max == 0.0 && $avg == 0.0) {
                return null;
            }
            return [
                'min' => $min,
                'max' => $max,
                'avg' => $avg,
                'median' => (float) ($bucket['median'] ?? $avg),
                'percentile' => (float) ($bucket['percentile'] ?? $min),
                'stddev' => (float) ($bucket['stddev'] ?? 0),
                'volume' => (int) ($bucket['volume'] ?? 0),
                'order_count' => (int) ($bucket['orderCount'] ?? $bucket['order_count'] ?? 0),
                'strategy' => 'goonpraisal',
                'updated_at' => now()->toIso8601String(),
            ];
        };

        return [
            'buy'  => $buildSide($buy),
            'sell' => $buildSide($sell),
        ];
    }

    /**
     * Resolve the Goonpraisal market slug for an MC market key.
     *
     * Priority:
     *   1. manager_core_markets.provider_slug on the matching row
     *   2. The market key itself if it's a known Goonpraisal slug
     *
     * Returns null when neither matches — caller logs + bails.
     */
    protected function resolveSlug(string $marketKey): ?string
    {
        try {
            $row = \DB::table('manager_core_markets')
                ->where('key', $marketKey)
                ->where('provider', 'goonpraisal')
                ->first(['provider_slug']);
            if ($row && !empty($row->provider_slug)) {
                return $row->provider_slug;
            }
        } catch (\Throwable $e) {
            // Table missing during install? Try the fallback path.
        }
        if (in_array($marketKey, self::SUPPORTED_SLUGS, true)) {
            return $marketKey;
        }
        return null;
    }

    /**
     * Construct the User-Agent header. Goonpraisal explicitly requests
     * an identifying UA — failure to provide one risks being blocked.
     */
    protected function buildUserAgent(): string
    {
        $contact = Setting::get('pricing.goonpraisal_contact_email',
            config('manager-core.pricing.goonpraisal.contact_email', self::DEFAULT_CONTACT_EMAIL));
        if (empty($contact)) {
            $contact = self::DEFAULT_CONTACT_EMAIL;
        }
        return sprintf(
            'SeAT-ManagerCore/%s (https://github.com/mattfalahe/manager-core; %s)',
            $this->pluginVersion(),
            $contact
        );
    }

    /**
     * Best-effort plugin version lookup. Falls back to a static label
     * if composer.json isn't readable.
     */
    protected function pluginVersion(): string
    {
        try {
            $composer = base_path('vendor/mattfalahe/manager-core/composer.json');
            if (is_readable($composer)) {
                $json = json_decode(file_get_contents($composer), true);
                if (isset($json['version'])) {
                    return (string) $json['version'];
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }
        return 'dev';
    }

    /**
     * Persist a Goonpraisal item response into MarketPrice + PriceHistory.
     *
     * Goonpraisal returns full statistics per side (min/max/avg/median/
     * percentile/volume/order_count), so we store everything — gives
     * callers maximum flexibility on the read path.
     */
    protected function storeFromGoonpraisalItem(int $typeId, string $market, array $item): void
    {
        $prices = is_array($item['prices'] ?? null) ? $item['prices'] : [];
        $buy  = is_array($prices['buy']  ?? null) ? $prices['buy']  : null;
        $sell = is_array($prices['sell'] ?? null) ? $prices['sell'] : null;

        if ($buy) {
            $this->saveSide($typeId, $market, 'buy', $buy);
        }
        if ($sell) {
            $this->saveSide($typeId, $market, 'sell', $sell);
        }
        $this->updateHistory($typeId, $market, $buy, $sell);
    }

    protected function saveSide(int $typeId, string $market, string $priceType, array $bucket): void
    {
        $min = (float) ($bucket['min'] ?? 0);
        $max = (float) ($bucket['max'] ?? 0);
        $avg = (float) ($bucket['avg'] ?? 0);
        if ($min == 0.0 && $max == 0.0 && $avg == 0.0) {
            // No orders — skip writing a zero row so we don't overwrite
            // a real cached price from a different provider that may
            // have data for this type.
            return;
        }

        MarketPrice::updateOrCreate(
            [
                'type_id' => $typeId,
                'market' => $market,
                'price_type' => $priceType,
            ],
            [
                'price_min' => $min,
                'price_max' => $max,
                'price_avg' => $avg,
                'price_median' => (float) ($bucket['median'] ?? $avg),
                'price_percentile' => (float) ($bucket['percentile'] ?? $min),
                'price_stddev' => (float) ($bucket['stddev'] ?? 0),
                'volume' => (int) ($bucket['volume'] ?? 0),
                'order_count' => (int) ($bucket['order_count'] ?? 0),
                'strategy' => 'goonpraisal',
                'updated_at' => now(),
            ]
        );
    }

    protected function updateHistory(int $typeId, string $market, ?array $buy, ?array $sell): void
    {
        $date = now()->toDateString();
        $avgBuy  = $buy  ? (float) ($buy['avg']  ?? 0) : 0;
        $avgSell = $sell ? (float) ($sell['avg'] ?? 0) : 0;
        $maxBuy  = $buy  ? (float) ($buy['max']  ?? 0) : 0;
        $minSell = $sell ? (float) ($sell['min'] ?? 0) : 0;
        $vol     = (int)  (($buy['volume'] ?? 0) + ($sell['volume'] ?? 0));

        PriceHistory::updateOrCreate(
            [
                'type_id' => $typeId,
                'market'  => $market,
                'date'    => $date,
            ],
            [
                'avg_buy'      => $avgBuy,
                'avg_sell'     => $avgSell,
                'max_buy'      => $maxBuy,
                'min_sell'     => $minSell,
                'total_volume' => $vol,
            ]
        );
    }

    public function getName(): string
    {
        return 'Goonpraisal';
    }

    /**
     * Goonpraisal is a free public service — always "available" so long
     * as the operator has set a contact email (or accepts the default).
     */
    public function isAvailable(): bool
    {
        return true;
    }
}
