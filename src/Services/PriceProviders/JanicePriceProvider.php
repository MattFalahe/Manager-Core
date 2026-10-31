<?php

namespace ManagerCore\Services\PriceProviders;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\Market;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\PriceHistory;
use ManagerCore\Models\Setting;

/**
 * Janice appraisal-service price provider.
 *
 * Posts the type ids to https://janice.e-351.com/api/rest/v2/pricer?market={id}
 * as plain text, one per line, and gets back one PricerItem per id Janice
 * knows. Janice is an authenticated service — operators must paste an API key
 * from https://janice.e-351.com/ into MC Settings before this provider works.
 *
 * Returns BOTH sides on every item (immediatePrices.buyPrice +
 * immediatePrices.sellPrice), so MC's appraisal logic can pick either side.
 *
 * Market params:
 *   '2' = Jita (default)
 *   '1' = Amarr
 * A market routed through Janice names its Janice market in the slug field
 * ('jita' or 'amarr'), since a custom market's key can be anything. For any
 * other market (hek, dodixie, rens, citadel markets), we fall back to Jita
 * because Janice doesn't track those hubs.
 *
 * Why the ids are batched: Janice publishes no rate limit, but its owner
 * blocks keys for excessive traffic, and asking one price per request made
 * a refresh of a few hundred types a few hundred requests. The pricer takes
 * a list and answers with the same prices the per-type endpoint gives.
 */
class JanicePriceProvider implements PriceProviderInterface
{
    use ReportsFetchFailures;

    private const PRICER_URL = 'https://janice.e-351.com/api/rest/v2/pricer';
    private const HTTP_TIMEOUT = 30;

    /**
     * Ids per request, and the pause between requests. A large refresh is a
     * handful of requests at this size, spaced out rather than in a burst.
     */
    private const BATCH_SIZE = 100;
    private const BATCH_PAUSE_MICROSECONDS = 2000000;

    /**
     * A request that fails (server error, timeout, rejected body) is retried
     * in halves, at most this many levels deep: one bad id or a blip should
     * not cost the whole batch.
     */
    private const MAX_SPLITS = 3;
    private const RETRY_PAUSE_MICROSECONDS = 1000000;

    /**
     * The retreat is what makes a bad run long, up to fifteen requests for one
     * batch, each on its own timeout. Past this nothing new is asked for, and
     * whatever is left keeps its cached price until the next run.
     */
    private const TIME_BUDGET_SECONDS = 120;

    /** The key, not the batch: wrong or blocked key, or asked too often. */
    private const REFUSED_STATUSES = [401, 403, 429];

    /**
     * Janice's last answer. Install-wide rather than per market, because one
     * key serves every market and every plugin override routed through Janice.
     */
    public const STATUS_KEY = 'pricing.janice_status';

    /**
     * Markets Janice tracks → URL param. Anything not in this map falls
     * back to Jita.
     */
    private const MARKET_PARAMS = [
        'jita' => '2',
        'amarr' => '1',
    ];

    protected ?string $apiKey;

    /**
     * Requests that failed outright during the current fetch, and what the
     * last one said. The retreat swallows those, so without this a Janice
     * that is down would look the same as types nobody trades.
     */
    protected int $requestErrors = 0;
    protected ?string $lastError = null;

    /** Ids the current fetch gave up on because its time ran out. */
    protected int $unasked = 0;

    /** Ids that went out in a request Janice answered, priced or not. */
    protected array $askedIds = [];

    protected float $deadline = 0.0;

    public function __construct()
    {
        $this->apiKey = Setting::get('pricing.janice_api_key', config('manager-core.pricing.janice.api_key'));
    }

    public function getPrices(array $typeIds, string $market, bool $persist = true): array
    {
        $this->resetFetchFailures();
        $out = array_fill_keys(array_map('intval', $typeIds), null);

        if (empty($typeIds)) {
            return $out;
        }

        if (empty($this->apiKey)) {
            Log::warning("[Manager Core] Janice provider: no API key configured. Set pricing.janice_api_key in MC Settings.");
            $this->recordFetchFailure(array_keys($out), 'Janice has no API key', false);
            return $out;
        }

        $marketParam = $this->marketParam($market);

        $persistTag = $persist ? '' : ' (no-persist override mode)';
        Log::info("[Manager Core] Fetching prices from Janice for " . count($out) . " types in {$market} (param={$marketParam}){$persistTag}");

        $this->requestErrors = 0;
        $this->lastError = null;
        $this->unasked = 0;
        $this->askedIds = [];
        $this->deadline = $this->clock() + self::TIME_BUDGET_SECONDS;

        $items = [];
        $refusal = null;

        try {
            foreach (array_chunk(array_keys($out), self::BATCH_SIZE) as $index => $batch) {
                if ($this->outOfTime()) {
                    $this->unasked += count($batch);
                    continue;
                }

                if ($index > 0) {
                    $this->pause(self::BATCH_PAUSE_MICROSECONDS);
                }

                $items += $this->fetchBatch($batch, $marketParam);
            }
        } catch (JaniceRefusedException $e) {
            $refusal = $e;
            Log::error("[Manager Core] {$e->getMessage()}. Stopped asking Janice for this fetch; anything not fetched keeps its cached price.");
        }

        if ($this->unasked > 0) {
            Log::warning("[Manager Core] Janice: ran out of time with {$this->unasked} types not asked for; they keep their cached prices until the next run");
        }

        // Everything no answered request covered: pieces the retreat gave up
        // on, the rest of a refused fetch, and what time ran out for. A 429
        // is worth another try later; a refused key isn't until it changes.
        $unanswered = array_values(array_diff(array_keys($out), array_keys($this->askedIds)));
        if (!empty($unanswered)) {
            if ($refusal !== null) {
                $tooMany = $refusal->getCode() === 429;
                $this->recordFetchFailure($unanswered, $refusal->getMessage(), $tooMany, $tooMany ? Market::STATUS_RATE_LIMITED : Market::STATUS_ERROR);
            } else {
                $this->recordFetchFailure($unanswered, $this->lastError ?? 'Janice ran out of time before asking for them', true, Market::STATUS_PROVIDER_DOWN);
            }
        }

        $answered = 0;
        foreach ($items as $typeId => $item) {
            if (!array_key_exists($typeId, $out)) {
                continue;
            }

            // Build the in-memory return value first — required by
            // Option B's no-persist override path.
            $out[$typeId] = $this->buildStatsFromJaniceResponse($item);
            if ($out[$typeId]['buy'] === null && $out[$typeId]['sell'] === null) {
                continue;
            }

            $answered++;
            if ($persist) {
                $this->storeJaniceRow($typeId, $market, $item);
            }
        }

        $this->recordOutcome($answered, $refusal);

        Log::info("[Manager Core] Janice completed: " . ($persist ? 'updated' : 'fetched') . " {$answered}/" . count($out) . " types in {$market}");
        return $out;
    }

    /**
     * Janice's param for an MC market: the slug of a market routed through
     * Janice, then the market key itself, then Jita.
     */
    protected function marketParam(string $market): string
    {
        $slug = null;
        try {
            $slug = Market::where('key', $market)->where('provider', 'janice')->value('provider_slug');
        } catch (\Throwable $e) {
            // Table missing during install: the key alone still works.
        }

        foreach ([$slug, $market] as $candidate) {
            $param = self::MARKET_PARAMS[strtolower(trim((string) $candidate))] ?? null;
            if ($param !== null) {
                return $param;
            }
        }

        Log::info("[Manager Core] Janice: market '{$market}' isn't tracked by Janice; falling back to Jita");
        return self::MARKET_PARAMS['jita'];
    }

    /**
     * Whether Janice prices this market key itself, rather than Jita in its
     * place. What decides whether Janice can stand in for another provider.
     */
    public static function tracksMarket(string $market): bool
    {
        return isset(self::MARKET_PARAMS[strtolower(trim($market))]);
    }

    /**
     * One batch, with a staged retreat when the request itself fails.
     *
     * @return array<int, array> PricerItems keyed by type id
     * @throws JaniceRefusedException
     */
    protected function fetchBatch(array $typeIds, string $marketParam, int $depth = 0): array
    {
        try {
            $items = $this->pricerRequest($typeIds, $marketParam);

            foreach ($typeIds as $typeId) {
                $this->askedIds[(int) $typeId] = true;
            }

            return $items;
        } catch (JaniceRefusedException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->requestErrors++;
            $this->lastError = $e->getMessage();

            Log::warning("[Manager Core] Janice request failed", [
                'ids' => count($typeIds),
                'depth' => $depth,
                'error' => $e->getMessage(),
            ]);

            if (count($typeIds) === 1 || $depth >= self::MAX_SPLITS) {
                return [];
            }

            $items = [];
            foreach (array_chunk($typeIds, (int) ceil(count($typeIds) / 2)) as $piece) {
                if ($this->outOfTime()) {
                    $this->unasked += count($piece);
                    continue;
                }

                $this->pause(self::RETRY_PAUSE_MICROSECONDS);
                $items += $this->fetchBatch($piece, $marketParam, $depth + 1);
            }

            return $items;
        }
    }

    /**
     * The pricer call itself.
     *
     * @return array<int, array> PricerItems keyed by type id
     * @throws JaniceRefusedException when Janice turns the key away
     * @throws \RuntimeException when the request fails in a way worth retrying
     */
    protected function pricerRequest(array $typeIds, string $marketParam): array
    {
        $response = Http::timeout(self::HTTP_TIMEOUT)
            ->withHeaders([
                'X-ApiKey' => $this->apiKey,
                'accept' => 'application/json',
            ])
            ->withBody(implode("\n", $typeIds), 'text/plain')
            ->post(self::PRICER_URL . '?market=' . $marketParam);

        if (in_array($response->status(), self::REFUSED_STATUSES, true)) {
            throw new JaniceRefusedException($response->status());
        }

        if (!$response->successful()) {
            throw new \RuntimeException('Janice returned HTTP ' . $response->status());
        }

        $data = $response->json();

        // A lone PricerItem rather than a list, should a one-id request
        // ever be answered that way.
        if (is_array($data) && isset($data['itemType'])) {
            $data = [$data];
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Janice returned something other than a list of prices');
        }

        $items = [];
        foreach ($data as $item) {
            $typeId = is_array($item) ? (int) ($item['itemType']['eid'] ?? 0) : 0;
            if ($typeId > 0) {
                $items[$typeId] = $item;
            }
        }

        return $items;
    }

    /**
     * Build the in-memory stats map for one Janice PricerItem.
     * Mirrors storeJaniceRow's shape decisions: min/max/avg carry the
     * single representative price, median + percentile carry the
     * 5-day median.
     *
     * @return array{buy: ?array, sell: ?array}
     */
    protected function buildStatsFromJaniceResponse(array $data): array
    {
        $imm = is_array($data['immediatePrices'] ?? null) ? $data['immediatePrices'] : [];
        $top5 = is_array($data['top5AveragePrices'] ?? null) ? $data['top5AveragePrices'] : [];

        $buyPrice = (float) ($imm['buyPrice'] ?? 0);
        $sellPrice = (float) ($imm['sellPrice'] ?? 0);
        $top5Buy = (float) ($top5['buyPrice5DayMedian'] ?? $top5['buyPrice'] ?? $buyPrice);
        $top5Sell = (float) ($top5['sellPrice5DayMedian'] ?? $top5['sellPrice'] ?? $sellPrice);

        $buildSide = function (float $price, float $stable): ?array {
            if ($price <= 0) return null;
            return [
                'min' => $price,
                'max' => $price,
                'avg' => $price,
                'median' => $stable > 0 ? $stable : $price,
                'percentile' => $stable > 0 ? $stable : $price,
                'stddev' => 0.0,
                'volume' => 0,
                'order_count' => 0,
                'strategy' => 'janice',
                'updated_at' => now()->toIso8601String(),
            ];
        };

        return [
            'buy'  => $buildSide($buyPrice, $top5Buy),
            'sell' => $buildSide($sellPrice, $top5Sell),
        ];
    }

    /**
     * Persist a Janice PricerItem into MarketPrice + PriceHistory.
     *
     * Janice's shape:
     *   {
     *     "itemType": { "eid": 34, ... },
     *     "immediatePrices": { "buyPrice": ..., "sellPrice": ..., "splitPrice": ... },
     *     "top5AveragePrices": { ... },
     *     ...
     *   }
     *
     * MC's MarketPrice table expects min/max/avg/median/percentile per side.
     * Janice gives us a single representative price per side; we mirror it
     * across the columns rather than synthesising a fake distribution.
     *
     * A side with no price is not written, so it keeps the price and the
     * timestamp of the last fetch that had one, and stays visibly stale.
     */
    protected function storeJaniceRow(int $typeId, string $market, array $data): void
    {
        $imm = is_array($data['immediatePrices'] ?? null) ? $data['immediatePrices'] : [];
        $top5 = is_array($data['top5AveragePrices'] ?? null) ? $data['top5AveragePrices'] : [];

        $buyPrice = (float) ($imm['buyPrice'] ?? 0);
        $sellPrice = (float) ($imm['sellPrice'] ?? 0);
        $top5Buy = (float) ($top5['buyPrice5DayMedian'] ?? $top5['buyPrice'] ?? $buyPrice);
        $top5Sell = (float) ($top5['sellPrice5DayMedian'] ?? $top5['sellPrice'] ?? $sellPrice);

        if ($buyPrice > 0) {
            $this->saveSide($typeId, $market, 'buy', $buyPrice, $top5Buy);
        }
        if ($sellPrice > 0) {
            $this->saveSide($typeId, $market, 'sell', $sellPrice, $top5Sell);
        }

        PriceHistory::recordDay($typeId, $market, $buyPrice, $buyPrice, $sellPrice, $sellPrice);
    }

    /**
     * Save one side (buy or sell) using Janice's single representative price.
     * min/max/avg all carry the same value because Janice doesn't expose a
     * distribution. median + percentile carry the 5-day median so callers
     * who specifically want "stable" pricing have a sensible signal.
     */
    protected function saveSide(int $typeId, string $market, string $priceType, float $price, float $stable): void
    {
        MarketPrice::updateOrCreate(
            [
                'type_id' => $typeId,
                'market' => $market,
                'price_type' => $priceType,
            ],
            [
                'price_min' => $price,
                'price_max' => $price,
                'price_avg' => $price,
                'price_median' => $stable > 0 ? $stable : $price,
                'price_percentile' => $stable > 0 ? $stable : $price,
                'price_stddev' => 0,
                'volume' => 0,         // Janice doesn't expose volume in pricer
                'order_count' => 0,
                'strategy' => 'janice',
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Keep Janice's last answer for the Watchdog and Diagnostics. A refusal,
     * or a fetch in which every request failed, marks it failing; a price
     * clears that. An answer with no prices in it changes nothing, since that
     * is normal for types nobody trades.
     */
    protected function recordOutcome(int $answered, ?JaniceRefusedException $refusal): void
    {
        if ($refusal === null && $answered === 0 && $this->requestErrors === 0) {
            return;
        }

        try {
            $previous = Setting::get(self::STATUS_KEY);
            $previous = is_array($previous) ? $previous : [];
            $wasFailing = ($previous['state'] ?? 'ok') !== 'ok';
            $now = now()->toIso8601String();

            if ($refusal === null && $answered > 0) {
                $status = [
                    'state' => 'ok',
                    'error' => null,
                    'since' => null,
                    'last_success' => $now,
                ];
            } else {
                $status = [
                    'state' => $refusal !== null ? 'refused' : 'failing',
                    'error' => $refusal !== null ? $refusal->getMessage() : $this->lastError,
                    'since' => $wasFailing ? ($previous['since'] ?? $now) : $now,
                    'last_success' => $previous['last_success'] ?? null,
                ];
            }
            $status['checked_at'] = $now;

            Setting::set(self::STATUS_KEY, $status, 'pricing');
        } catch (\Throwable $e) {
            // A status note must never break a price fetch.
            Log::warning('[Manager Core] Janice: could not record the provider status: ' . $e->getMessage());
        }
    }

    /**
     * Janice's last recorded answer when it was a refusal or a failure, null
     * while it is answering or before it has been asked.
     *
     * @return array{state: string, error: ?string, since: ?string, last_success: ?string, checked_at: ?string}|null
     */
    public static function lastFailure(): ?array
    {
        try {
            $status = Setting::get(self::STATUS_KEY);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($status) || ($status['state'] ?? 'ok') === 'ok') {
            return null;
        }

        return $status + ['error' => null, 'since' => null, 'last_success' => null, 'checked_at' => null];
    }

    protected function outOfTime(): bool
    {
        return $this->clock() >= $this->deadline;
    }

    protected function clock(): float
    {
        return microtime(true);
    }

    protected function pause(int $microseconds): void
    {
        usleep($microseconds);
    }

    public function getName(): string
    {
        return 'Janice';
    }

    /**
     * Janice requires an API key. Without one the provider is dormant —
     * the settings page will show "(needs API key)" against the option.
     */
    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }
}
