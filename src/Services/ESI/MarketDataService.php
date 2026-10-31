<?php

namespace ManagerCore\Services\ESI;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\ESI\EsiKeyHolder;
use ManagerCore\Models\Market;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\PriceHistory;
use ManagerCore\Models\TypeSubscription;
use ManagerCore\Services\PriceProviders\ReportsFetchFailures;
use Psr\Http\Client\NetworkExceptionInterface;
use Seat\Eseye\Exceptions\EsiScopeAccessDeniedException;
use Seat\Eseye\Exceptions\InvalidAuthenticationException;
use Seat\Eseye\Exceptions\InvalidContainerDataException;
use Seat\Eseye\Exceptions\RequestFailedException;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Services\Contracts\EsiClient;

/**
 * MarketDataService - Fetches market data from ESI
 *
 * Based on go-evepraisal's ESI fetcher
 */
class MarketDataService
{
    use ReportsFetchFailures;

    /**
     * ESI base URL
     */
    protected $baseUrl;

    /**
     * Request timeout
     */
    protected $timeout;

    /**
     * Scope a character's token needs to read a structure's market.
     */
    public const STRUCTURE_MARKET_SCOPE = 'esi-markets.structure_markets.v1';

    /**
     * How long one read of a structure market answers later requests.
     * ESI caches the endpoint for five minutes, so a read inside that
     * window costs nothing anyway. This decides how stale a price may get
     * before an on-demand request (an appraisal, a new subscription) reads
     * the structure again.
     */
    protected const STRUCTURE_FRESH_SECONDS = 900;

    /**
     * Key Pool characters tried per read before giving up.
     */
    protected const STRUCTURE_MAX_READERS = 5;

    /**
     * Safety cap only; X-Pages ends a read. 500 pages is 500,000 orders.
     */
    protected const STRUCTURE_MAX_PAGES = 500;

    /**
     * Further tries for one page of a structure read after an ESI error or a
     * request that got no answer, the pause growing by this much each time.
     * A read is all pages or nothing, so one bad page of fifty would
     * otherwise throw the other forty-nine away.
     */
    protected const STRUCTURE_PAGE_RETRIES = 2;
    protected const STRUCTURE_PAGE_RETRY_PAUSE_SECONDS = 2;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->baseUrl = config('manager-core.esi.base_url', 'https://esi.evetech.net/latest');
        $this->timeout = config('manager-core.esi.timeout', 30);
    }

    /**
     * Update market prices for given type IDs.
     *
     * Dispatches to the right fetcher based on the market's type. Hub
     * markets (Jita, Amarr, etc.) use the public ESI region-orders
     * endpoint. Citadel markets (player-owned structures with a market
     * module) read the structure's own order book through the
     * authenticated structure-markets endpoint, as a director from the
     * ESI Key Pool. See updateStructureMarketPrices().
     *
     * Operators choose which markets are citadel vs hub via the Markets
     * admin page. The market_type column on manager_core_markets is
     * the discriminator.
     *
     * @param array $typeIds
     * @param string $market
     * @return void
     */
    public function updateMarketPrices(array $typeIds, $market = 'jita')
    {
        $this->resetFetchFailures();

        // ESIPriceProvider answers by reading the cache back, so the types
        // with fresh prices are kept here: what this fetch priced, and what
        // a fetch recent enough to stand for one did.
        $this->freshTypeIds = [];

        $marketConfig = \ManagerCore\Models\Market::getMarketConfig($market);

        if (!$marketConfig) {
            Log::error("[Manager Core] Unknown market: {$market}");
            $this->recordFetchFailure($typeIds, "Unknown market '{$market}'", false);
            return;
        }

        $marketType = $marketConfig['market_type'] ?? \ManagerCore\Models\Market::TYPE_HUB;
        if ($marketType === \ManagerCore\Models\Market::TYPE_CITADEL) {
            $this->updateStructureMarketPrices($typeIds, $market, $marketConfig);
            return;
        }

        $regionId = $marketConfig['region_id'];
        $systemIds = $marketConfig['system_ids'] ?? [];

        // Freshness short-circuit. Skip type_ids whose buy AND sell rows
        // were updated within the configured hub TTL — most ticks during
        // an hour see the same subscribed types and re-fetching unchanged
        // prices wastes ESI budget. The configured TTL is operator-tunable
        // via Setting('pricing.hub_ttl_seconds') with a 30-minute default
        // that aligns with CCP's market-cache TTL.
        $hubTtlSeconds = (int) \ManagerCore\Helpers\Settings::get(
            'pricing.hub_ttl_seconds',
            'pricing.hub_ttl_seconds',
            1800  // 30 min default
        );
        $totalRequested = count($typeIds);
        $requested = $typeIds;
        $typeIds = $this->filterFreshHubTypes($typeIds, $market, $hubTtlSeconds);
        $this->recordFresh(array_diff($requested, $typeIds));
        $skipped = $totalRequested - count($typeIds);
        if ($skipped > 0) {
            Log::info("[Manager Core] Skipped {$skipped}/{$totalRequested} types in '{$market}' — prices fresh within {$hubTtlSeconds}s TTL");
        }
        if (empty($typeIds)) {
            Log::info("[Manager Core] All requested types in '{$market}' are within TTL; nothing to fetch.");
            return;
        }

        Log::info("[Manager Core] Fetching market orders for region {$regionId} ({$market}) - " . count($typeIds) . " types");

        $updatedCount = 0;
        $totalTypes = count($typeIds);

        // Process types in batches for concurrent requests.
        //
        // ESI's rate limit is ~100 req/sec sustained per IP for public
        // endpoints. Batch size 25 with 0.5s pacing between batches sits
        // safely at ~50 req/sec peak. Previous batches of 10 were overly
        // conservative — bumping to 25 gives ~2.5x speed on big refresh
        // jobs without putting us anywhere near the error budget.
        $batchSize = 25;
        $batches = array_chunk($typeIds, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            try {
                // Fetch first page for all types in this batch concurrently
                $responses = Http::pool(function ($pool) use ($batch, $regionId) {
                    foreach ($batch as $typeId) {
                        $url = "{$this->baseUrl}/markets/{$regionId}/orders/?datasource=tranquility&order_type=all&type_id={$typeId}&page=1";
                        $pool->as((string) $typeId)->connectTimeout(5)->timeout($this->timeout)->get($url);
                    }
                });

                // Process responses for this batch
                foreach ($batch as $typeId) {
                    try {
                        $response = $responses[$typeId] ?? null;

                        // A request that got no answer at all (a timeout, a
                        // refused connection) comes back from the pool as its
                        // exception, not as a response.
                        if (!$response instanceof Response) {
                            $error = $response instanceof \Throwable ? $response->getMessage() : 'no response';
                            Log::error("[Manager Core] ESI request got no answer for type {$typeId}: {$error}");
                            $this->recordFetchFailure([$typeId], "ESI did not answer: {$error}", true, Market::STATUS_PROVIDER_DOWN);
                            continue;
                        }

                        if ($response->failed()) {
                            if ($response->status() === 404) {
                                // No orders for this type, skip silently
                                continue;
                            }
                            Log::error("[Manager Core] ESI request failed for type {$typeId} - Status: {$response->status()}");
                            $status = $this->retryableEsiStatus($response->status());
                            if ($status !== null) {
                                $this->recordFetchFailure([$typeId], 'ESI returned HTTP ' . $response->status(), true, $status);
                            }
                            continue;
                        }

                        $typeOrders = [];
                        $orders = $response->json();

                        if (empty($orders)) {
                            continue;
                        }

                        // Filter by system if specified
                        foreach ($orders as $order) {
                            if (empty($systemIds) || in_array($order['system_id'] ?? 0, $systemIds)) {
                                $typeOrders[] = $order;
                            }
                        }

                        // Check if there are more pages
                        $totalPages = (int) $response->header('X-Pages', 1);

                        // Limit to reasonable number of pages to prevent memory issues
                        $maxPages = 10;
                        if ($totalPages > $maxPages) {
                            Log::warning("[Manager Core] Type {$typeId} has {$totalPages} pages, limiting to {$maxPages} pages");
                            $totalPages = $maxPages;
                        }

                        // Fetch remaining pages if any (sequentially for this type)
                        $pageError = null;
                        if ($totalPages > 1) {
                            for ($page = 2; $page <= $totalPages; $page++) {
                                try {
                                    $url = "{$this->baseUrl}/markets/{$regionId}/orders/?datasource=tranquility&order_type=all&type_id={$typeId}&page={$page}";
                                    $pageResponse = Http::connectTimeout(5)->timeout($this->timeout)->get($url);

                                    if (!$pageResponse->successful()) {
                                        $pageError = "ESI returned HTTP {$pageResponse->status()} for page {$page}";
                                        break;
                                    }

                                    $pageOrders = $pageResponse->json();
                                    if (empty($pageOrders)) {
                                        break;
                                    }

                                    // Filter by system if specified
                                    foreach ($pageOrders as $order) {
                                        if (empty($systemIds) || in_array($order['system_id'] ?? 0, $systemIds)) {
                                            $typeOrders[] = $order;
                                        }
                                    }
                                } catch (\Exception $e) {
                                    Log::error("[Manager Core] Error fetching page {$page} for type {$typeId}: " . $e->getMessage());
                                    $pageError = "ESI page {$page} failed: " . $e->getMessage();
                                    break;
                                }
                            }
                        }

                        // The pages that did come back are only part of the
                        // order book, and the best orders may be on the one
                        // that didn't. The cached price is the better guess.
                        if ($pageError !== null) {
                            $this->recordFetchFailure([$typeId], $pageError, true, Market::STATUS_PROVIDER_DOWN);
                            continue;
                        }

                        // Calculate and save prices for this type
                        if (!empty($typeOrders)) {
                            $this->calculateAndSavePrices($typeId, $typeOrders, $market);
                            $this->recordFresh([$typeId]);
                            $updatedCount++;
                        }

                        // Clear memory after processing each type
                        unset($typeOrders, $orders, $response);

                    } catch (\Exception $e) {
                        Log::error("[Manager Core] Error processing type {$typeId}: " . $e->getMessage());
                        $this->recordFetchFailure([$typeId], 'ESI: ' . $e->getMessage(), true, Market::STATUS_PROVIDER_DOWN);
                        continue;
                    }
                }

                // Clear batch responses from memory
                unset($responses);

                // Log progress after each batch
                $processedSoFar = min(($batchIndex + 1) * $batchSize, $totalTypes);
                Log::info("[Manager Core] Processed {$processedSoFar}/{$totalTypes} types, updated {$updatedCount} with prices");

                // Small delay between batches to respect rate limits and prevent overwhelming the server
                if ($batchIndex < count($batches) - 1) {
                    usleep(500000); // 0.5 second delay between batches
                }

            } catch (\Exception $e) {
                Log::error("[Manager Core] Error processing batch {$batchIndex}: " . $e->getMessage());
                $this->recordFetchFailure($batch, 'ESI: ' . $e->getMessage(), true, Market::STATUS_PROVIDER_DOWN);
                continue;
            }
        }

        Log::info("[Manager Core] Completed: Updated prices for {$updatedCount}/{$totalTypes} types in {$market}");
    }

    /**
     * The market status a failed ESI answer means when asking again later
     * can help, or null when it can't: other client errors are about the
     * request itself and would fail the same way.
     */
    protected function retryableEsiStatus(int $status): ?string
    {
        if ($status === 420 || $status === 429) {
            return Market::STATUS_RATE_LIMITED;
        }

        return $status >= 500 ? Market::STATUS_PROVIDER_DOWN : null;
    }
    /**
     * Filter a list of type_ids down to those that DON'T have a fresh
     * MarketPrice row for this market within the TTL window.
     *
     * "Fresh" means BOTH sides (buy + sell) updated within $ttlSeconds.
     * If only buy or only sell exists, the type still gets re-fetched —
     * catches the case where ESI started returning one-sided orders.
     *
     * Returns an empty array when every requested type is fresh (caller
     * short-circuits without making any ESI calls).
     *
     * Used by the hub-market refresh path to skip wasted re-fetches of
     * types whose prices haven't aged out yet. Significant speedup on
     * frequent appraisals that hit the same subscribed type pool.
     */
    protected function filterFreshHubTypes(array $typeIds, string $market, int $ttlSeconds): array
    {
        if ($ttlSeconds <= 0 || empty($typeIds)) {
            return $typeIds;
        }
        $threshold = now()->subSeconds($ttlSeconds);

        // Find which (type_id, market) pairs have BOTH sides fresh.
        // GROUP BY + HAVING is fast on the existing (type_id, market)
        // index; no full table scan.
        try {
            $freshTypeIds = MarketPrice::where('market', $market)
                ->whereIn('type_id', $typeIds)
                ->where('updated_at', '>=', $threshold)
                ->groupBy('type_id')
                ->havingRaw('COUNT(*) >= 2')  // both buy + sell rows present
                ->pluck('type_id')
                ->map(fn($id) => (int) $id)
                ->toArray();
        } catch (\Throwable $e) {
            // Table missing during install / fresh boot — assume nothing is
            // fresh so the refresh proceeds normally.
            return $typeIds;
        }

        $freshSet = array_flip($freshTypeIds);
        return array_values(array_filter($typeIds,
            fn($id) => !isset($freshSet[(int) $id])
        ));
    }

    /**
     * Price a citadel market from the structure's own order book.
     *
     * ESI's /markets/structures/{structure_id}/ has no type filter: every
     * page is up to 1,000 orders of any type. One read of all pages
     * therefore prices every type at once, and its cost depends only on
     * the size of the market (one request per 1,000 orders), not on how
     * many types or plugins ask. So each read covers the requested types
     * plus everything plugins subscribe to on this market, and answers
     * later requests for STRUCTURE_FRESH_SECONDS.
     *
     * The structure ID lives in the market's provider_slug, the field that
     * holds each provider's own identifier for a market.
     *
     * A failed read is reported through lastFetchFailures() for every
     * requested type. PricingService keeps the market's status from that,
     * since only the market's own provider should set it: a Goonpraisal
     * market read through a plugin's ESI override has no structure ID, and
     * that says nothing about Goonpraisal.
     */
    protected function updateStructureMarketPrices(array $typeIds, string $market, array $marketConfig): void
    {
        $requested = array_values(array_unique(array_map('intval', $typeIds)));

        $structureId = Market::structureIdFromSlug($marketConfig['provider_slug'] ?? null);
        if (!$structureId) {
            Log::warning("[Manager Core] Market '{$market}' has no structure ID in its slug, so ESI can't read it.");
            $this->recordFetchFailure($requested,
                "No structure ID. Put the structure's ID in the market's slug field to read it through ESI.", false);
            return;
        }

        if ($this->structureReadCovers($market, $requested)) {
            Log::info("[Manager Core] Structure market '{$market}': the last read is recent and covers all " . count($requested) . " requested type(s).");
            $this->recordFresh($this->pricedByLastRead($market, $requested));
            return;
        }

        try {
            Cache::lock('mc.structure_market.' . $market . '.read', 900)->block(120, function () use ($market, $structureId, $requested) {
                // Another worker may have finished a read while this one waited.
                if ($this->structureReadCovers($market, $requested)) {
                    $this->recordFresh($this->pricedByLastRead($market, $requested));
                    return;
                }

                $this->readStructureMarket($market, $structureId, $requested);
            });
        } catch (LockTimeoutException $e) {
            Log::warning("[Manager Core] Structure market '{$market}': another read was still running after 120s; cached prices stay as they are.");
            $this->recordFetchFailure($requested, 'Another read of this structure was still running after two minutes');
        }
    }

    /**
     * One full read of a structure market, tried with each candidate
     * character until one can open it. A failed read leaves the cached
     * prices alone.
     */
    protected function readStructureMarket(string $market, int $structureId, array $requested): void
    {
        $wanted = array_values(array_unique(array_merge($requested, $this->subscribedTypeIds($market))));
        $wantedSet = array_flip($wanted);

        $readers = $this->structureReaders($market);
        if (empty($readers)) {
            Log::warning("[Manager Core] Structure market '{$market}': no ESI Key Pool character has the " . self::STRUCTURE_MARKET_SCOPE . " scope.");
            $this->recordFetchFailure($requested,
                'No character to read this structure with. Add a director who can dock there to the ESI Key Pool; their token needs the ' . self::STRUCTURE_MARKET_SCOPE . ' scope.', false);
            return;
        }

        $failures = [];
        $status = Market::STATUS_ERROR;
        $retryable = false;

        foreach ($readers as $token) {
            $name = $this->characterLabel((int) $token->character_id);

            try {
                $read = $this->fetchStructureOrders($token, $structureId, $wantedSet);
            } catch (\Throwable $e) {
                [$status, $reason, $tryNext, $retryable] = $this->classifyStructureFailure($e);
                $failures[] = "{$name}: {$reason}";
                Log::info("[Manager Core] Structure market '{$market}': {$name} couldn't read it ({$reason}).");

                if ($tryNext) {
                    continue;
                }
                break;
            }

            foreach ($read['byType'] as $typeId => $orders) {
                $this->calculateAndSavePrices($typeId, array_values($orders), $market);
            }

            Cache::forever($this->structureReaderKey($market), (int) $token->character_id);
            Cache::put($this->structureReadKey($market), [
                'at' => now()->getTimestamp(),
                'types' => $wanted,
                'priced' => count($read['byType']),
                'priced_types' => array_keys($read['byType']),
                'pages' => $read['pages'],
                'orders' => $read['orders'],
                'character' => $name,
            ], now()->addDays(7));

            $this->recordFresh(array_intersect($requested, array_keys($read['byType'])));

            Log::info("[Manager Core] Structure market '{$market}': read {$read['pages']} page(s), {$read['orders']} order(s) as {$name}; priced " . count($read['byType']) . " of " . count($wanted) . " type(s).");
            return;
        }

        // The last character's failure decides whether a later try can help:
        // a loop that ran through every reader ended on a token or access
        // problem, one that stopped early on ESI itself.
        $this->recordFetchFailure($requested, implode(' | ', $failures), $retryable, $status);
        Log::warning("[Manager Core] Structure market '{$market}' could not be read; cached prices stay as they are. " . implode(' | ', $failures));
    }

    /**
     * Read every page of a structure's market with one character's token.
     *
     * The page goes in the query string through page(), before each call.
     * invoke() only takes path values, and Eseye clears the query string
     * after every request, so a page handed to invoke() as an extra
     * argument is dropped and every request comes back as page 1.
     *
     * Throws on the first page that still fails after its retries: a read
     * is all pages or nothing.
     *
     * @return array{byType: array<int, array<int, array>>, pages: int, orders: int}
     */
    protected function fetchStructureOrders(RefreshToken $token, int $structureId, array $wantedSet): array
    {
        $esi = app()->make(EsiClient::class);
        $esi->setAuthentication($token);

        $byType = [];
        $orders = 0;
        $pages = 1;
        $page = 1;

        try {
            do {
                $response = $this->fetchStructurePage($esi, $structureId, $page);

                if ($page === 1) {
                    $reported = (int) ($response->getPagesCount() ?? 1);
                    $pages = max(1, min($reported, self::STRUCTURE_MAX_PAGES));

                    if ($reported > self::STRUCTURE_MAX_PAGES) {
                        Log::warning("[Manager Core] Structure {$structureId} reports {$reported} pages; reading the first " . self::STRUCTURE_MAX_PAGES . '.');
                    }
                }

                $body = $response->getBody();
                foreach (is_array($body) ? $body : [] as $order) {
                    $orders++;
                    $typeId = (int) ($order->type_id ?? 0);
                    if (!isset($wantedSet[$typeId])) {
                        continue;
                    }

                    // Keyed by order ID: if ESI's cache turns over during a
                    // read, an order can turn up on two pages.
                    $byType[$typeId][(int) $order->order_id] = [
                        'is_buy_order' => (bool) $order->is_buy_order,
                        'price' => (float) $order->price,
                        'volume_remain' => (int) $order->volume_remain,
                    ];
                }

                unset($response, $body);
                $page++;
            } while ($page <= $pages);
        } finally {
            $this->saveRefreshedToken($esi, $token);
        }

        return ['byType' => $byType, 'pages' => $pages, 'orders' => $orders];
    }

    /**
     * One page of a structure's market. An ESI server error, or a request
     * that got no answer, is tried again after a pause; anything else is
     * about the token or the structure and fails at once.
     */
    protected function fetchStructurePage(EsiClient $esi, int $structureId, int $page)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $esi->page($page)->invoke('get', '/markets/structures/{structure_id}/', [
                    'structure_id' => $structureId,
                ]);
            } catch (\Throwable $e) {
                if ($attempt > self::STRUCTURE_PAGE_RETRIES || !$this->isTransientEsiFailure($e)) {
                    throw $e;
                }

                Log::info("[Manager Core] Structure {$structureId}: page {$page} failed (" . $e->getMessage() . "); trying it again.");
                $this->pause(self::STRUCTURE_PAGE_RETRY_PAUSE_SECONDS * $attempt);
            }
        }
    }

    /**
     * An ESI server error, or a request that never got an answer.
     */
    protected function isTransientEsiFailure(\Throwable $e): bool
    {
        if ($e instanceof RequestFailedException) {
            return (int) $e->getCode() >= 500;
        }

        return $e instanceof NetworkExceptionInterface || $e instanceof ConnectionException;
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Characters to read a structure market with: ESI Key Pool members
     * whose SeAT token has the structure-markets scope, the one that last
     * read this market first. The pool holds the directors the operator
     * already lets Manager Core call ESI as, and a director can normally
     * dock at their own corporation's structures.
     *
     * @return RefreshToken[]
     */
    protected function structureReaders(string $market): array
    {
        $ids = EsiKeyHolder::where('enabled', true)
            ->orderBy('id')
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $last = (int) Cache::get($this->structureReaderKey($market), 0);
        if ($last && in_array($last, $ids, true)) {
            $ids = array_merge([$last], array_values(array_diff($ids, [$last])));
        }

        $readers = [];
        foreach ($ids as $id) {
            $token = RefreshToken::find($id);
            if (!$token) {
                continue;
            }

            $scopes = $token->scopes ?? [];
            if (is_string($scopes)) {
                $scopes = json_decode($scopes, true) ?? [];
            }
            if (!in_array(self::STRUCTURE_MARKET_SCOPE, (array) $scopes, true)) {
                continue;
            }

            $readers[] = $token;
            if (count($readers) >= self::STRUCTURE_MAX_READERS) {
                break;
            }
        }

        return $readers;
    }

    /**
     * Turn a failed read into [market status, reason, try the next
     * character, worth trying again later].
     *
     * Token and access problems belong to the character, so another one may
     * get in, but no later try fixes them. A missing structure, rate limiting
     * or an ESI outage would fail the same way for everyone, so the read
     * stops there; the last two pass with time.
     */
    protected function classifyStructureFailure(\Throwable $e): array
    {
        $code = $e instanceof RequestFailedException ? (int) $e->getCode() : 0;
        $detail = trim($e->getMessage());

        if ($e instanceof EsiScopeAccessDeniedException) {
            return [Market::STATUS_ERROR, 'token lacks the ' . self::STRUCTURE_MARKET_SCOPE . ' scope', true, false];
        }
        if ($e instanceof InvalidAuthenticationException || $e instanceof InvalidContainerDataException
            || $code === 401 || ($code === 400 && stripos($detail, 'invalid') !== false)) {
            return [Market::STATUS_ERROR, 'token rejected' . ($detail !== '' ? ": {$detail}" : ''), true, false];
        }
        if ($code === 403) {
            return [Market::STATUS_ERROR, "no access to this structure's market (403)", true, false];
        }
        if ($code === 404) {
            return [Market::STATUS_ERROR, 'structure not found (404): destroyed, unanchored, or a wrong ID', false, false];
        }
        if ($code === 420 || $code === 429) {
            return [Market::STATUS_RATE_LIMITED, "ESI rate limited the read ({$code})", false, true];
        }
        if ($code >= 500) {
            return [Market::STATUS_PROVIDER_DOWN, "ESI error {$code}" . ($detail !== '' ? ": {$detail}" : ''), false, true];
        }
        if ($e instanceof NetworkExceptionInterface || $e instanceof ConnectionException) {
            return [Market::STATUS_PROVIDER_DOWN, 'ESI did not answer' . ($detail !== '' ? ": {$detail}" : ''), false, true];
        }

        return [Market::STATUS_ERROR, $detail !== '' ? $detail : get_class($e), false, true];
    }

    /**
     * Eseye refreshes an expired access token in memory only. Write it back
     * to SeAT, as the ESI Key Pool poll does, so the next call doesn't have
     * to refresh it again.
     */
    protected function saveRefreshedToken(EsiClient $esi, RefreshToken $token): void
    {
        try {
            $auth = $esi->getAuthentication();
            $token->token = $auth->getAccessToken();
            $token->refresh_token = $auth->getRefreshToken();
            $token->expires_on = $auth->getExpiresOn();
            $token->save();
        } catch (\Throwable $e) {
            Log::debug("[Manager Core] Could not save the refreshed token for character {$token->character_id}: " . $e->getMessage());
        }
    }

    /**
     * Whether the last read of this market is recent enough and covered
     * every requested type, priced or found with no orders.
     */
    protected function structureReadCovers(string $market, array $typeIds): bool
    {
        $read = Cache::get($this->structureReadKey($market));
        if (!is_array($read) || ($read['at'] ?? 0) < now()->subSeconds(self::STRUCTURE_FRESH_SECONDS)->getTimestamp()) {
            return false;
        }

        return empty(array_diff($typeIds, $read['types'] ?? []));
    }

    /**
     * The requested types the last read of this market found orders for.
     * A read kept before it listed them counts every type it covered.
     */
    protected function pricedByLastRead(string $market, array $typeIds): array
    {
        $read = $this->lastStructureRead($market);

        return array_values(array_intersect($typeIds, $read['priced_types'] ?? $typeIds));
    }

    /**
     * The last successful read of a structure market (at, pages, orders,
     * priced, character), or null before the first one. Shown by the
     * Markets page's Test button.
     */
    public function lastStructureRead(string $market): ?array
    {
        $read = Cache::get($this->structureReadKey($market));

        return is_array($read) ? $read : null;
    }

    /**
     * Make the next request for this market read the structure again.
     */
    public function forgetStructureRead(string $market): void
    {
        Cache::forget($this->structureReadKey($market));
    }

    protected function subscribedTypeIds(string $market): array
    {
        try {
            return TypeSubscription::where('market', $market)
                ->distinct()
                ->pluck('type_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function characterLabel(int $characterId): string
    {
        try {
            $name = DB::table('character_infos')->where('character_id', $characterId)->value('name');
        } catch (\Throwable $e) {
            $name = null;
        }

        return $name ? "{$name} ({$characterId})" : "character {$characterId}";
    }

    protected function structureReadKey(string $market): string
    {
        return 'mc.structure_market.' . $market . '.last_read';
    }

    protected function structureReaderKey(string $market): string
    {
        return 'mc.structure_market.' . $market . '.reader';
    }

    /**
     * Calculate price statistics from orders and save to database
     *
     * Based on go-evepraisal's getPriceAggregatesForOrders
     *
     * @param int $typeId
     * @param array $orders
     * @param string $market
     * @return void
     */
    protected function calculateAndSavePrices($typeId, array $orders, $market)
    {
        $buyOrders = [];
        $sellOrders = [];

        foreach ($orders as $order) {
            if ($order['is_buy_order']) {
                $buyOrders[] = $order;
            } else {
                $sellOrders[] = $order;
            }
        }

        // Calculate buy price statistics
        if (!empty($buyOrders)) {
            $buyStats = $this->calculatePriceStats($buyOrders);
            $this->savePriceStats($typeId, $market, 'buy', $buyStats);
        }

        // Calculate sell price statistics
        if (!empty($sellOrders)) {
            $sellStats = $this->calculatePriceStats($sellOrders);
            $this->savePriceStats($typeId, $market, 'sell', $sellStats);
        }

        // Update daily price history
        $this->updatePriceHistory($typeId, $market, $buyOrders, $sellOrders);
    }

    /**
     * Calculate price statistics from orders
     *
     * Implements statistical calculations from go-evepraisal
     *
     * @param array $orders
     * @return array
     */
    protected function calculatePriceStats(array $orders)
    {
        $prices = array_column($orders, 'price');
        $volumes = array_column($orders, 'volume_remain');

        sort($prices);

        $totalVolume = array_sum($volumes);
        $orderCount = count($orders);

        // Weighted average
        $weightedSum = 0;
        foreach ($orders as $order) {
            $weightedSum += $order['price'] * $order['volume_remain'];
        }
        $avg = $totalVolume > 0 ? $weightedSum / $totalVolume : 0;

        // Percentiles (simplified - not weighted)
        $min = min($prices);
        $max = max($prices);
        $median = $this->percentile($prices, 0.5);
        $percentile = $this->percentile($prices, 0.05); // 5th percentile

        // Standard deviation
        $stddev = $this->standardDeviation($prices);

        return [
            'min' => $min,
            'max' => $max,
            'avg' => $avg,
            'median' => $median,
            'percentile' => $percentile,
            'stddev' => $stddev,
            'volume' => $totalVolume,
            'order_count' => $orderCount,
        ];
    }

    /**
     * Calculate percentile from sorted array
     *
     * @param array $sortedValues
     * @param float $percentile
     * @return float
     */
    protected function percentile(array $sortedValues, $percentile)
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0;
        }

        $index = ($percentile * ($count - 1));
        $lower = floor($index);
        $upper = ceil($index);

        if ($lower === $upper) {
            return $sortedValues[$lower];
        }

        $fraction = $index - $lower;
        return $sortedValues[$lower] + ($sortedValues[$upper] - $sortedValues[$lower]) * $fraction;
    }

    /**
     * Calculate standard deviation
     *
     * @param array $values
     * @return float
     */
    protected function standardDeviation(array $values)
    {
        $count = count($values);
        if ($count === 0) {
            return 0;
        }

        $mean = array_sum($values) / $count;
        $variance = 0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        return sqrt($variance / $count);
    }

    /**
     * Save price statistics to database
     *
     * @param int $typeId
     * @param string $market
     * @param string $priceType
     * @param array $stats
     * @return void
     */
    protected function savePriceStats($typeId, $market, $priceType, array $stats)
    {
        MarketPrice::updateOrCreate(
            [
                'type_id' => $typeId,
                'market' => $market,
                'price_type' => $priceType,
            ],
            [
                'price_min' => $stats['min'],
                'price_max' => $stats['max'],
                'price_avg' => $stats['avg'],
                'price_median' => $stats['median'],
                'price_percentile' => $stats['percentile'],
                'price_stddev' => $stats['stddev'],
                'volume' => $stats['volume'],
                'order_count' => $stats['order_count'],
                'strategy' => 'orders',
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Update daily price history
     *
     * A side with no orders keeps the figure the day's row already has,
     * which matters most for structure markets, where many items only
     * ever have sell orders.
     *
     * @param int $typeId
     * @param string $market
     * @param array $buyOrders
     * @param array $sellOrders
     * @return void
     */
    protected function updatePriceHistory($typeId, $market, array $buyOrders, array $sellOrders)
    {
        $avgBuy = !empty($buyOrders) ? array_sum(array_column($buyOrders, 'price')) / count($buyOrders) : 0;
        $avgSell = !empty($sellOrders) ? array_sum(array_column($sellOrders, 'price')) / count($sellOrders) : 0;
        $maxBuy = !empty($buyOrders) ? max(array_column($buyOrders, 'price')) : 0;
        $minSell = !empty($sellOrders) ? min(array_column($sellOrders, 'price')) : 0;

        $totalVolume = array_sum(array_column(array_merge($buyOrders, $sellOrders), 'volume_remain'));

        PriceHistory::recordDay((int) $typeId, (string) $market, (float) $avgBuy, (float) $maxBuy, (float) $avgSell, (float) $minSell, (int) $totalVolume);
    }
}
