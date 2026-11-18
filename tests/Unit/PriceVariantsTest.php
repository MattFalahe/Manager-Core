<?php

namespace ManagerCore\Tests\Unit;

use Carbon\Carbon;
use ManagerCore\Services\ESI\MarketDataService;
use ManagerCore\Services\PriceProviders\ESIPriceProvider;
use ManagerCore\Services\PriceProviders\PriceProviderInterface;
use ManagerCore\Services\PricingService;
use ManagerCore\Tests\ArrayCache;
use ManagerCore\Tests\ArrayLog;
use ManagerCore\Topics;
use PHPUnit\Framework\TestCase;

/**
 * A plugin may name any provider for its prices at any market, and gets that
 * provider's price, kept apart from the market's own. Nothing stands in for
 * it, and a provider is never asked about a place it would price at Jita 4-4
 * instead.
 */
class PriceVariantsTest extends TestCase
{
    /** Tritanium and Pyerite at Jita 4-4 on 8 November, through Janice. */
    private const JANICE = [
        34 => ['buy' => ['min' => 3.92, 'max' => 3.92], 'sell' => ['min' => 4.16, 'max' => 4.16]],
        35 => ['buy' => null, 'sell' => ['min' => 12.0, 'max' => 12.0]],
    ];

    /** The same two through Fuzzwork, which the market itself is set to. */
    private const FUZZWORK = [
        34 => ['buy' => ['min' => 3.93, 'max' => 3.93], 'sell' => ['min' => 4.09, 'max' => 4.09]],
        35 => ['buy' => ['min' => 10.0, 'max' => 10.0], 'sell' => ['min' => 11.8, 'max' => 11.8]],
    ];

    private const MARKETS = [
        'jita' => ['name' => 'Jita', 'market_type' => 'hub', 'provider' => 'fuzzwork', 'provider_slug' => null, 'region_id' => 10000002, 'is_enabled' => true],
        'amarr' => ['name' => 'Amarr', 'market_type' => 'hub', 'provider' => 'janice', 'provider_slug' => null, 'region_id' => 10000043, 'is_enabled' => true],
        'hek' => ['name' => 'Hek', 'market_type' => 'hub', 'provider' => 'fuzzwork', 'provider_slug' => null, 'region_id' => 10000042, 'is_enabled' => false],
        'c-j6mt' => ['name' => 'C-J6MT', 'market_type' => 'citadel', 'provider' => 'esi', 'provider_slug' => '1049588174021', 'region_id' => null, 'is_enabled' => true],
    ];

    protected function setUp(): void
    {
        ArrayCache::$items = [];
        ArrayLog::$lines = [];
        Carbon::setTestNow('2026-11-17 12:07:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    private static function provider(array $answer = self::JANICE, bool $available = true): PriceProviderInterface
    {
        return new class ($answer, $available) implements PriceProviderInterface {
            public array $asked = [];
            public array $failed = [];
            public ?string $throws = null;

            public function __construct(private array $answer, public bool $available)
            {
            }

            public function getPrices(array $typeIds, string $market, bool $persist = true): array
            {
                $this->asked[] = ['types' => $typeIds, 'market' => $market, 'persist' => $persist];
                if ($this->throws !== null) {
                    throw new \RuntimeException($this->throws);
                }

                return array_intersect_key($this->answer, array_flip($typeIds)) + array_fill_keys($typeIds, null);
            }

            public function lastFetchFailures(): array
            {
                return ['type_ids' => $this->failed, 'error' => $this->failed ? 'Janice returned HTTP 503' : null, 'retryable' => true, 'status' => null];
            }

            public function lastFreshTypeIds(): ?array
            {
                return null;
            }

            public function getName(): string
            {
                return 'Test provider';
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }

    /**
     * The real variants and the real market refresh, with the tables, the
     * queue, the providers and the event bus replaced.
     *
     * @param array<int, array{0: string, 1: string, 2: string, 3: int}> $subscriptions plugin, market, provider, type
     * @param array<string, array<int, int>> $ages seconds since each variant was stored, by market@provider; a type left out has no price
     */
    private static function service(array $subscriptions = [], array $ages = [], array $providers = []): PricingService
    {
        $providers += ['janice' => self::provider(), 'fuzzwork' => self::provider(self::FUZZWORK), 'esi' => self::provider(), 'goonpraisal' => self::provider()];

        return new class ($subscriptions, $ages, $providers, self::MARKETS, self::FUZZWORK) extends PricingService {
            public array $queued = [];
            public array $written = [];
            public array $announced = [];
            public array $marketEvents = [];
            public array $fetched = [];
            public array $retried = [];
            public array $bumped = [];

            /** @var array<string, int[]> the market's own subscriptions, by market */
            public array $typeSubscriptions = [];

            /** Laid over what the market's own provider answers. */
            public array $outcome = [];

            /** @var array<string, array<int, array>> the market's own stored prices, by market and type */
            public array $marketPrices = [];

            public function readOverride(string $plugin, string $provider, string $market, array $typeIds, string $priceType = 'both'): array
            {
                return $this->overridePrices($plugin, $provider, $market, $typeIds, $priceType);
            }

            protected function fetchPricesForTypes(array $typeIds, $market, $priceType): array
            {
                $prices = [];
                foreach ($typeIds as $typeId) {
                    $both = $this->marketPrices[$market][$typeId] ?? ['buy' => null, 'sell' => null];
                    $prices[$typeId] = $priceType === 'both' ? $both : ($both[$priceType] ?? null);
                }

                return $prices;
            }

            protected function pluginTypeSubscriptions(string $pluginName, string $market): array
            {
                return $this->typeSubscriptions[$market] ?? [];
            }

            protected function deleteVariantSubscriptions(string $pluginName, ?string $market, ?string $provider, ?array $typeIds): int
            {
                $before = count($this->subscriptions);
                $this->subscriptions = array_values(array_filter($this->subscriptions, fn ($s) => !($s[0] === $pluginName
                    && ($market === null || $s[1] === $market) && ($provider === null || $s[2] === $provider)
                    && ($typeIds === null || in_array($s[3], $typeIds, true)))));

                return $before - count($this->subscriptions);
            }

            public function __construct(public array $subscriptions, public array $ages, public array $providers,
                private array $markets, private array $ownPrices)
            {
            }

            protected function effectiveMarkets(bool $enabledOnly): array
            {
                return array_filter($this->markets, fn ($row) => !$enabledOnly || $row['is_enabled']);
            }

            protected function resolveMarketProvider(string $marketKey): ?string
            {
                return $this->markets[$marketKey]['provider'] ?? null;
            }

            protected function getPriceProvider(?string $override = null)
            {
                return $this->providers[$override ?? 'fuzzwork'] ?? throw new \RuntimeException("No provider '{$override}' here");
            }

            private function typesFor(string $market, string $provider, ?string $plugin = null): array
            {
                $rows = array_filter($this->subscriptions, fn ($s) => $s[1] === $market && $s[2] === $provider && ($plugin === null || $s[0] === $plugin));

                return array_values(array_unique(array_column($rows, 3)));
            }

            private function pluginsFor(string $market, string $provider, ?array $typeIds = null): array
            {
                $rows = array_filter($this->subscriptions, fn ($s) => $s[1] === $market && $s[2] === $provider && ($typeIds === null || in_array($s[3], $typeIds, true)));
                $plugins = array_values(array_unique(array_column($rows, 0)));
                sort($plugins);

                return $plugins;
            }

            protected function storeVariantSubscriptions(string $pluginName, array $typeIds, string $market, string $provider, int $priority): void
            {
                foreach ($typeIds as $typeId) {
                    if (!in_array([$pluginName, $market, $provider, $typeId], $this->subscriptions, true)) {
                        $this->subscriptions[] = [$pluginName, $market, $provider, $typeId];
                    }
                }
            }

            protected function variantTypeIdsFor(string $market, string $provider): array
            {
                return $this->typesFor($market, $provider);
            }

            protected function variantSubscribedAmong(string $market, string $provider, array $typeIds): array
            {
                return array_values(array_intersect($typeIds, $this->typesFor($market, $provider)));
            }

            protected function variantPluginTypeIds(string $pluginName, string $market, string $provider, array $typeIds): array
            {
                return array_values(array_intersect($typeIds, $this->typesFor($market, $provider, $pluginName)));
            }

            protected function variantSubscribersOf(string $market, string $provider, array $typeIds): array
            {
                return $this->pluginsFor($market, $provider, $typeIds);
            }

            protected function variantPluginsAt(string $market, string $provider): array
            {
                return $this->pluginsFor($market, $provider);
            }

            protected function variantPairs(): array
            {
                $pairs = [];
                foreach ($this->subscriptions as $s) {
                    $pairs[$s[1] . '@' . $s[2]] = [$s[1], $s[2]];
                }

                return array_values($pairs);
            }

            protected function variantTypeIdsOlderThan(string $market, string $provider, array $typeIds, int $seconds): array
            {
                $ages = $this->ages[$market . '@' . $provider] ?? [];

                return array_values(array_filter($typeIds, fn ($id) => ($ages[$id] ?? PHP_INT_MAX) > $seconds));
            }

            protected function variantTypeIdsWithoutPrice(string $market, string $provider, array $typeIds): array
            {
                $ages = $this->ages[$market . '@' . $provider] ?? [];

                return array_values(array_filter($typeIds, fn ($id) => !isset($ages[$id])));
            }

            protected function variantPriceSummary(string $market, string $provider): array
            {
                return ['priced' => count($this->ages[$market . '@' . $provider] ?? []), 'newest' => null, 'oldest' => null];
            }

            protected function writeVariantRows(array $rows): void
            {
                foreach ($rows as $row) {
                    $this->written[] = $row;
                }
            }

            protected function storedVariantRows(array $typeIds, string $market, string $provider, array $sides): iterable
            {
                foreach ($this->written as $row) {
                    if ($row['market'] === $market && $row['provider'] === $provider
                        && in_array($row['type_id'], $typeIds, true) && in_array($row['price_type'], $sides, true)) {
                        yield (object) $row;
                    }
                }
            }

            protected function dispatchVariantRefresh(string $market, string $provider, ?array $typeIds, string $trigger, ?string $for = null): void
            {
                $this->queued[] = ['market' => $market, 'provider' => $provider, 'types' => $typeIds, 'trigger' => $trigger, 'for' => $for];
            }

            protected function publishVariantsRefreshed(array $payload): void
            {
                $this->announced[] = $payload;
            }

            public function getSubscribedTypes($market = null)
            {
                return [$market => new class ($this->typeSubscriptions[$market] ?? []) {
                    public function __construct(private array $ids)
                    {
                    }

                    public function pluck(string $column): self
                    {
                        return $this;
                    }

                    public function unique(): self
                    {
                        return $this;
                    }

                    public function toArray(): array
                    {
                        return $this->ids;
                    }
                }];
            }

            protected function fetchWithFallback(string $providerKey, string $market, array $typeIds, bool $persist): array
            {
                $this->fetched[] = ['provider' => $providerKey, 'market' => $market, 'types' => $typeIds, 'persist' => $persist];

                return $this->outcome + ['prices' => array_intersect_key($this->ownPrices, array_flip($typeIds)), 'asked' => count($typeIds),
                    'failed' => [], 'unanswered' => [], 'stood_in' => [], 'priced' => array_values(array_intersect($typeIds, array_keys($this->ownPrices))),
                    'provider' => 'Fuzzwork', 'error' => null, 'retryable' => false, 'status' => null, 'outright' => false, 'fallback' => null];
            }

            protected function scheduleRetry(string $market, array $outcome): bool
            {
                $this->retried[] = $market;

                return false;
            }

            protected function recordMarketOutcome(string $market, array $outcome, bool $retrying): void
            {
            }

            protected function bumpPriceCacheVersion(string $market): void
            {
                $this->bumped[] = $market;
            }

            protected function subscribersOf(string $market, array $typeIds): array
            {
                return ['mining-manager'];
            }

            protected function publishRefreshed(array $payload): void
            {
                $this->marketEvents[] = $payload;
            }

            protected function noteScheduledRefresh(): void
            {
            }
        };
    }

    public function test_a_plugin_may_name_any_provider_that_prices_the_place(): void
    {
        $pricing = self::service();

        $answer = $pricing->subscribeVariants('market-manager', [34, '35', 34, 0, -1], 'jita', ' Janice ');

        $this->assertSame(['subscribed' => 2, 'reason' => null, 'place_reason' => null], $answer);
        $this->assertSame([['market-manager', 'jita', 'janice', 34], ['market-manager', 'jita', 'janice', 35]], $pricing->subscriptions);
        $this->assertSame([['market' => 'jita', 'provider' => 'janice', 'types' => [34, 35], 'trigger' => 'subscription', 'for' => null]], $pricing->queued);

        // MCPraisal reads Jita's region, and Goonpraisal knows Jita by name.
        $this->assertSame(1, $pricing->subscribeVariants('market-manager', [34], 'jita', 'esi')['subscribed']);
        $this->assertSame(1, $pricing->subscribeVariants('mining-manager', [34], 'jita', 'goonpraisal')['subscribed']);
    }

    public function test_a_provider_that_would_price_somewhere_else_is_refused(): void
    {
        $pricing = self::service();

        $fuzzwork = $pricing->subscribeVariants('market-manager', [34], 'c-j6mt', 'fuzzwork');
        $this->assertSame('cannot_price_place', $fuzzwork['reason']);
        $this->assertStringContainsString('Jita 4-4', $fuzzwork['place_reason']);

        // Janice prices Amarr for the market, and MCPraisal can read its region beside it.
        $esi = $pricing->subscribeVariants('market-manager', [34], 'amarr', 'esi');
        $this->assertNull($esi['reason']);
        $this->assertSame(['subscribed' => 0, 'reason' => 'unknown_provider', 'place_reason' => null], $pricing->subscribeVariants('market-manager', [34], 'jita', 'evepraisal'));
        $this->assertSame('unknown_market', $pricing->subscribeVariants('market-manager', [34], 'perimeter', 'janice')['reason']);
        $this->assertSame('cannot_price_place', $pricing->subscribeVariants('market-manager', [34], 'jita', 'seat')['reason']);

        $this->assertSame([['market-manager', 'amarr', 'esi', 34]], $pricing->subscriptions);
    }

    public function test_the_place_reason_is_the_same_one_a_subscription_gets(): void
    {
        $pricing = self::service();

        $this->assertNull($pricing->variantPlaceReason('Janice', 'amarr'));
        $this->assertNull($pricing->variantPlaceReason('esi', 'c-j6mt'));
        $this->assertStringContainsString('Jita 4-4', $pricing->variantPlaceReason('janice', 'c-j6mt'));
        $this->assertStringContainsString('perimeter', $pricing->variantPlaceReason('janice', 'perimeter'));
    }

    /** Hek is disabled, but its subscriptions are kept for when it is enabled again. */
    public function test_a_disabled_market_may_be_subscribed_to_and_waits(): void
    {
        $pricing = self::service([['market-manager', 'hek', 'janice', 34]]);

        $this->assertSame(1, $pricing->subscribeVariants('market-manager', [35], 'hek', 'janice')['subscribed']);

        $pricing->refreshVariants('hek', 'janice', null);
        $this->assertSame([], $pricing->providers['janice']->asked);
        $this->assertSame([], $pricing->written);
    }

    /** A plugin that subscribes on every page load names the same items each time. */
    public function test_the_first_fetch_is_queued_once_for_what_has_no_price(): void
    {
        $pricing = self::service([], ['jita@janice' => [34 => 60]]);

        $pricing->subscribeVariants('market-manager', [34, 35], 'jita', 'janice');
        $this->assertSame([35], $pricing->queued[0]['types'], 'Tritanium has a price already');

        Carbon::setTestNow('2026-11-17 12:20:00');
        $pricing->subscribeVariants('market-manager', [34, 35], 'jita', 'janice');
        $this->assertCount(1, $pricing->queued);

        Carbon::setTestNow('2026-11-17 12:23:00');
        $pricing->subscribeVariants('market-manager', [34, 35], 'jita', 'janice');
        $this->assertCount(2, $pricing->queued);

        $pricing->subscribeVariants('market-manager', [36], 'jita', 'janice', 1, false);
        $this->assertCount(2, $pricing->queued);
    }

    /** The first fetch through Janice and the first through Fuzzwork are two different asks. */
    public function test_each_provider_at_a_market_is_queued_on_its_own(): void
    {
        $pricing = self::service();

        $pricing->subscribeVariants('market-manager', [34], 'jita', 'janice');
        $pricing->subscribeVariants('market-manager', [34], 'jita', 'esi');
        $pricing->subscribeVariants('market-manager', [34], 'amarr', 'esi');

        $this->assertSame(['jita@janice', 'jita@esi', 'amarr@esi'], array_map(fn ($q) => $q['market'] . '@' . $q['provider'], $pricing->queued));
    }

    public function test_another_provider_is_asked_without_storing_under_the_market(): void
    {
        $pricing = self::service([['market-manager', 'jita', 'janice', 34], ['market-manager', 'jita', 'janice', 35], ['buyback-manager', 'jita', 'janice', 35]]);

        $pricing->refreshVariants('jita', 'janice', null);

        $janice = $pricing->providers['janice'];
        $this->assertSame([['types' => [34, 35], 'market' => 'jita', 'persist' => false]], $janice->asked);
        $this->assertSame([], $pricing->fetched, 'the market\'s own provider was not asked');
        $this->assertSame([], $pricing->bumped, 'the market\'s own prices were not touched');

        // Pyerite had no buy order on Janice: no row, not a zero.
        $this->assertSame([[34, 'buy', 3.92], [34, 'sell', 4.16], [35, 'sell', 12.0]],
            array_map(fn ($row) => [$row['type_id'], $row['price_type'], $row['price_min']], $pricing->written));
        $this->assertSame(['janice'], array_unique(array_column($pricing->written, 'provider')));
        $this->assertSame(['jita'], array_unique(array_column($pricing->written, 'market')));

        $this->assertCount(1, $pricing->announced);
        $event = $pricing->announced[0];
        $this->assertSame(['market', 'provider', 'refreshed_at', 'plugins', 'type_count', 'stored_count', 'trigger'], array_keys($event));
        $this->assertSame(['jita', 'janice', ['buyback-manager', 'market-manager'], 2, 2, 'schedule'],
            [$event['market'], $event['provider'], $event['plugins'], $event['type_count'], $event['stored_count'], $event['trigger']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/', $event['refreshed_at']);
    }

    public function test_a_plugin_reads_back_the_provider_it_named(): void
    {
        $pricing = self::service([['market-manager', 'jita', 'janice', 34], ['market-manager', 'jita', 'janice', 35]]);
        $pricing->refreshVariants('jita', 'janice', null);

        $prices = $pricing->getVariantPrices([34, 35, 36], 'jita', 'Janice');

        $this->assertSame([34, 35, 36], array_keys($prices));
        $this->assertSame('janice', $prices[34]['sell']['provider']);
        $this->assertSame(4.16, $prices[34]['sell']['min']);
        $this->assertSame(3.92, $prices[34]['buy']['max']);
        $this->assertSame('2026-11-17 12:07:00', $prices[34]['sell']['updated_at']->format('Y-m-d H:i:s'));
        $this->assertNull($prices[35]['buy']);
        $this->assertSame(['buy' => null, 'sell' => null], $prices[36]);

        $this->assertSame(12.0, $pricing->getVariantPrices([35], 'jita', 'janice', 'sell')[35]['min']);
        $this->assertNull($pricing->getVariantPrices([35], 'jita', 'janice', 'buy')[35]);

        // Nothing through Fuzzwork, the market's own, until a plugin subscribes to that.
        $this->assertSame(['buy' => null, 'sell' => null], $pricing->getVariantPrices([34], 'jita', 'fuzzwork')[34]);
    }

    /** Nothing stands in for a provider a plugin named. Its last price stays. */
    public function test_a_type_the_provider_failed_on_keeps_its_last_price(): void
    {
        $janice = self::provider();
        $janice->failed = [35];
        $pricing = self::service([['market-manager', 'jita', 'janice', 34], ['market-manager', 'jita', 'janice', 35]], [], ['janice' => $janice]);

        $pricing->refreshVariants('jita', 'janice', null);

        $this->assertSame([34], array_values(array_unique(array_column($pricing->written, 'type_id'))));
        $this->assertCount(1, $janice->asked, 'asked once, with no fallback after it');
        $this->assertSame([], $pricing->retried);
        $this->assertSame(['asked' => 2, 'priced' => 1, 'failed' => 1, 'error' => 'Janice returned HTTP 503'],
            array_diff_key($pricing->lastVariantRefresh('jita', 'janice'), ['at' => true]));
    }

    public function test_a_provider_that_fails_outright_leaves_everything_as_it_was(): void
    {
        $janice = self::provider();
        $janice->throws = 'cURL error 28: timed out';
        $pricing = self::service([['market-manager', 'jita', 'janice', 34]], [], ['janice' => $janice]);

        $pricing->refreshVariants('jita', 'janice', null);

        $this->assertSame([], $pricing->written);
        $this->assertSame([], $pricing->announced);
        $this->assertSame([], $pricing->fetched, 'the market\'s own provider does not stand in');
        $this->assertSame('cURL error 28: timed out', $pricing->lastVariantRefresh('jita', 'janice')['error']);
    }

    public function test_a_provider_that_is_not_set_up_is_not_asked(): void
    {
        $janice = self::provider(self::JANICE, false);
        $pricing = self::service([['market-manager', 'jita', 'janice', 34]], [], ['janice' => $janice]);

        $pricing->refreshVariants('jita', 'janice', null);

        $this->assertSame([], $janice->asked);
        $this->assertSame([], $pricing->written);
        $this->assertSame('Janice is not set up', $pricing->lastVariantRefresh('jita', 'janice')['error']);
    }

    /** C-J6MT used to be priced through Fuzzwork, and a plugin subscribed then. */
    public function test_a_place_the_provider_cannot_price_is_never_asked_about(): void
    {
        $pricing = self::service([['market-manager', 'c-j6mt', 'fuzzwork', 34]]);

        $pricing->refreshVariants('c-j6mt', 'fuzzwork', null);

        $this->assertSame([], $pricing->providers['fuzzwork']->asked);
        $this->assertSame([], $pricing->written);
        $this->assertStringContainsString('Jita 4-4', $pricing->lastVariantRefresh('c-j6mt', 'fuzzwork')['error']);
    }

    /** The job waited on the queue while a plugin's ask fetched some of them. */
    public function test_what_got_a_price_a_moment_ago_is_left_out(): void
    {
        $pricing = self::service([['market-manager', 'jita', 'janice', 34], ['market-manager', 'jita', 'janice', 35]], ['jita@janice' => [34 => 120, 35 => 3400]]);

        $pricing->refreshVariants('jita', 'janice', null);

        $this->assertSame([35], $pricing->providers['janice']->asked[0]['types']);

        $pricing = self::service([['market-manager', 'jita', 'janice', 34]], ['jita@janice' => [34 => 120]]);
        $pricing->refreshVariants('jita', 'janice', null);
        $this->assertSame([], $pricing->providers['janice']->asked);
        $this->assertSame([], $pricing->announced);
    }

    /** Through the market's own provider a variant is the market's own price, fetched once for both. */
    public function test_the_markets_own_refresh_fetches_and_copies_its_variants(): void
    {
        $pricing = self::service([['market-manager', 'jita', 'fuzzwork', 35], ['market-manager', 'jita', 'fuzzwork', 34]]);
        $pricing->typeSubscriptions['jita'] = [34];

        $pricing->updatePrices('jita', null);

        $this->assertSame([['provider' => 'fuzzwork', 'market' => 'jita', 'types' => [34, 35], 'persist' => true]], $pricing->fetched);
        $this->assertSame([], $pricing->providers['fuzzwork']->asked, 'not asked a second time for the variants');
        $this->assertSame([[34, 'buy', 3.93], [34, 'sell', 4.09], [35, 'buy', 10.0], [35, 'sell', 11.8]],
            array_map(fn ($row) => [$row['type_id'], $row['price_type'], $row['price_min']], $pricing->written));
        $this->assertSame(['fuzzwork'], array_unique(array_column($pricing->written, 'provider')));

        $this->assertCount(1, $pricing->marketEvents);
        $this->assertCount(1, $pricing->announced);
        $this->assertSame(['market-manager'], $pricing->announced[0]['plugins']);
    }

    public function test_only_the_subscribed_types_the_own_provider_priced_are_copied(): void
    {
        $pricing = self::service([['market-manager', 'jita', 'fuzzwork', 35]]);

        $pricing->updatePrices('jita', [34, 35, 36]);

        $this->assertSame([35], array_values(array_unique(array_column($pricing->written, 'type_id'))));

        $pricing = self::service([['market-manager', 'jita', 'fuzzwork', 36]]);
        $pricing->updatePrices('jita', [34, 36]);
        $this->assertSame([], $pricing->written, 'Fuzzwork had no price for 36');
        $this->assertSame([], $pricing->announced);
    }

    /** Janice stood in at Jita while Fuzzwork was down: that is Janice's price, not Fuzzwork's. */
    public function test_a_price_a_fallback_stood_in_with_is_never_copied(): void
    {
        $pricing = self::service([['market-manager', 'jita', 'fuzzwork', 34]]);
        $pricing->outcome = ['outright' => true, 'stood_in' => [34], 'fallback' => ['label' => 'Janice']];

        $pricing->updatePrices('jita', [34]);

        $this->assertSame([], $pricing->written);
        $this->assertSame([], $pricing->announced);
    }

    public function test_a_named_own_provider_is_the_markets_own_refresh(): void
    {
        $pricing = self::service([['market-manager', 'jita', 'fuzzwork', 34]]);

        $pricing->refreshVariants('jita', 'fuzzwork', null);
        $this->assertSame([['provider' => 'fuzzwork', 'market' => 'jita', 'types' => [34], 'persist' => true]], $pricing->fetched);
        $this->assertSame(['jita'], $pricing->retried, 'the market\'s own refresh tries again as it always has');

        // A plugin's ask is not tried again: the plugin asks again.
        $pricing->refreshVariants('jita', 'fuzzwork', [34], PricingService::TRIGGER_REQUEST, 'market-manager');
        $this->assertCount(2, $pricing->fetched);
        $this->assertSame(['jita'], $pricing->retried);
        $this->assertSame(['market-manager'], $pricing->announced[1]['plugins']);
        $this->assertSame('request', $pricing->announced[1]['trigger']);
    }

    public function test_only_a_listed_plugin_may_ask_for_a_provider_between_refreshes(): void
    {
        $pricing = self::service([['mining-manager', 'jita', 'janice', 34]]);

        $this->assertSame(['queued' => 0, 'fresh' => 0, 'reason' => 'not_allowed'], $pricing->refreshIfOlder('mining-manager', [34], 'jita', 3600, 'janice'));
        $this->assertSame([], $pricing->queued);
    }

    public function test_an_ask_with_a_provider_fetches_only_that_plugins_old_variants(): void
    {
        $pricing = self::service([['market-manager', 'jita', 'janice', 34], ['market-manager', 'jita', 'janice', 35], ['buyback-manager', 'jita', 'janice', 36]],
            ['jita@janice' => [34 => 7200, 35 => 600]]);

        $answer = $pricing->refreshIfOlder('market-manager', [34, 35, 36], 'jita', 3600, 'Janice');

        $this->assertSame(['queued' => 1, 'fresh' => 1, 'reason' => null], $answer);
        $this->assertSame([['market' => 'jita', 'provider' => 'janice', 'types' => [34], 'trigger' => 'request', 'for' => 'market-manager']], $pricing->queued);

        $this->assertSame('not_subscribed', $pricing->refreshIfOlder('market-manager', [36], 'jita', 3600, 'janice')['reason']);
        $this->assertSame('unknown_provider', $pricing->refreshIfOlder('market-manager', [34], 'jita', 3600, 'evepraisal')['reason']);
        $this->assertSame('unknown_market', $pricing->refreshIfOlder('market-manager', [34], 'hek', 3600, 'janice')['reason']);
    }

    /** One Janice key serves every plugin, whichever market its prices are asked for at. */
    public function test_the_named_provider_sets_the_youngest_price_fetched_again(): void
    {
        // 40 minutes old, asked for with a one minute limit: past Fuzzwork's half hour, inside Janice's hour.
        $subscriptions = [['market-manager', 'jita', 'janice', 34], ['market-manager', 'jita', 'goonpraisal', 34]];
        $pricing = self::service($subscriptions, ['jita@janice' => [34 => 2400], 'jita@goonpraisal' => [34 => 2400]]);

        $this->assertSame(0, $pricing->refreshIfOlder('market-manager', [34], 'jita', 60, 'janice')['queued']);
        $this->assertSame(1, $pricing->refreshIfOlder('market-manager', [34], 'jita', 60, 'goonpraisal')['queued']);
    }

    /** A variant's asks are kept apart from the market's own, which another provider fetches. */
    public function test_a_type_asked_for_through_a_provider_waits_for_that_providers_floor(): void
    {
        $pricing = self::service([['market-manager', 'jita', 'goonpraisal', 34]], ['jita@goonpraisal' => [34 => 4000]]);
        ArrayCache::$items['mc.pricing.refresh_asked.jita'] = [34 => Carbon::now()->getTimestamp()];

        $this->assertSame(1, $pricing->refreshIfOlder('market-manager', [34], 'jita', 3600, 'goonpraisal')['queued']);

        Carbon::setTestNow('2026-11-17 12:30:00');
        $this->assertSame(0, $pricing->refreshIfOlder('market-manager', [34], 'jita', 3600, 'goonpraisal')['queued']);

        Carbon::setTestNow('2026-11-17 12:38:00');
        $this->assertSame(1, $pricing->refreshIfOlder('market-manager', [34], 'jita', 3600, 'goonpraisal')['queued']);
    }

    public function test_the_schedule_queues_one_fetch_per_market_and_named_provider(): void
    {
        $pricing = self::service([
            ['market-manager', 'jita', 'janice', 34], ['buyback-manager', 'jita', 'janice', 35],
            ['market-manager', 'jita', 'fuzzwork', 34],
            ['market-manager', 'amarr', 'esi', 34],
            ['market-manager', 'hek', 'janice', 34],
            ['market-manager', 'c-j6mt', 'esi', 34],
        ]);

        $this->assertSame(2, $pricing->queueVariantRefreshes());
        $this->assertSame([['jita', 'janice', null], ['amarr', 'esi', null]],
            array_map(fn ($q) => [$q['market'], $q['provider'], $q['types']], $pricing->queued));
        $this->assertSame(['schedule'], array_unique(array_column($pricing->queued, 'trigger')));

        $this->assertSame(1, $pricing->queueVariantRefreshes('amarr'));
    }

    public function test_diagnostics_sees_each_market_and_provider_plugins_name(): void
    {
        $janice = self::provider(self::JANICE, false);
        $pricing = self::service([
            ['market-manager', 'jita', 'janice', 34], ['buyback-manager', 'jita', 'janice', 35],
            ['market-manager', 'c-j6mt', 'fuzzwork', 34],
            ['market-manager', 'hek', 'goonpraisal', 34],
            ['market-manager', 'jita', 'fuzzwork', 34],
        ], ['jita@janice' => [34 => 60]], ['janice' => $janice]);
        $pricing->refreshVariants('jita', 'janice', null);

        $byPair = [];
        foreach ($pricing->variantHealth() as $row) {
            $byPair[$row['market'] . '@' . $row['provider']] = $row;
        }

        $this->assertSame(['jita@janice', 'c-j6mt@fuzzwork', 'hek@goonpraisal', 'jita@fuzzwork'], array_keys($byPair));

        $jita = $byPair['jita@janice'];
        $this->assertSame(['Jita', true, 'Janice', false, false, null, ['buyback-manager', 'market-manager'], 2, 1],
            [$jita['market_name'], $jita['market_enabled'], $jita['provider_label'], $jita['own'], $jita['available'], $jita['place_reason'], $jita['plugins'], $jita['subscribed'], $jita['priced']]);
        $this->assertSame('Janice is not set up', $jita['last_fetch']['error']);

        $this->assertStringContainsString('Jita 4-4', $byPair['c-j6mt@fuzzwork']['place_reason']);
        $this->assertFalse($byPair['hek@goonpraisal']['market_enabled']);
        $this->assertTrue($byPair['jita@fuzzwork']['own']);
        $this->assertNull($byPair['jita@fuzzwork']['last_fetch']);
    }

    public function test_the_topic_is_registered_as_manager_cores_own(): void
    {
        $topic = Topics::describe('pricing.variants_refreshed');

        $this->assertSame('manager-core', $topic['publisher']);
        $this->assertSame(['market', 'provider', 'refreshed_at'], $topic['required']);
        $this->assertSame('pricing.variants_refreshed:{market}:{provider}:{refreshed_at}', $topic['idempotency_template']);
        $this->assertSame(PricingService::VARIANTS_REFRESHED_TOPIC, 'pricing.variants_refreshed');
    }

    /** A price kept through a provider, as the variant table holds it. */
    private static function kept(string $market, string $provider, int $typeId, string $side, float $price): array
    {
        return ['type_id' => $typeId, 'market' => $market, 'provider' => $provider, 'price_type' => $side, 'price_min' => $price,
            'price_max' => $price, 'price_avg' => $price, 'price_median' => $price, 'price_percentile' => $price, 'price_stddev' => 0.0,
            'volume' => 0, 'order_count' => 0, 'strategy' => $provider, 'updated_at' => Carbon::parse('2026-11-17 10:00:00')];
    }

    public function test_an_override_is_answered_from_kept_prices_without_asking_the_provider(): void
    {
        $pricing = self::service([['buyback-manager', 'jita', 'janice', 34]], ['jita@janice' => [34 => 7200]]);
        $pricing->written = [self::kept('jita', 'janice', 34, 'buy', 3.92), self::kept('jita', 'janice', 34, 'sell', 4.16)];

        $answer = $pricing->readOverride('buyback-manager', 'Janice', 'jita', [34]);

        $this->assertSame([], $pricing->fetched);
        $this->assertSame(4.16, $answer[34]['sell']['min']);
        $this->assertSame(3.92, $answer[34]['buy']['max']);
        $this->assertSame('janice', $answer[34]['sell']['provider']);
        $this->assertSame([['buyback-manager', 'jita', 'janice', 34]], $pricing->subscriptions, 'already subscribed');
    }

    /** Twice the 4-hourly refresh: only a stalled refresh leaves a kept price this old. */
    public function test_a_kept_price_the_refresh_left_behind_is_fetched_while_the_plugin_waits(): void
    {
        $pricing = self::service([['buyback-manager', 'jita', 'janice', 34]], ['jita@janice' => [34 => PricingService::OVERRIDE_STALE_SECONDS - 60, 35 => PricingService::OVERRIDE_STALE_SECONDS + 60]]);
        $pricing->outcome = ['prices' => self::JANICE, 'priced' => [35]];

        $pricing->readOverride('buyback-manager', 'janice', 'jita', [34, 35]);

        $this->assertSame([['provider' => 'janice', 'market' => 'jita', 'types' => [35], 'persist' => false]], $pricing->fetched);
    }

    public function test_an_item_with_no_kept_price_is_fetched_kept_and_subscribed(): void
    {
        $pricing = self::service();
        $pricing->outcome = ['prices' => self::JANICE, 'priced' => [34, 35]];

        $answer = $pricing->readOverride('buyback-manager', 'janice', 'jita', [34, 35]);

        $this->assertSame([['provider' => 'janice', 'market' => 'jita', 'types' => [34, 35], 'persist' => false]], $pricing->fetched);
        $this->assertSame(4.16, $answer[34]['sell']['min']);
        $this->assertSame('janice', $answer[34]['sell']['provider']);
        $this->assertNull($answer[35]['buy'], 'Janice had no buy price for Pyerite');
        $this->assertSame(12.0, $answer[35]['sell']['min']);

        // Kept as Janice's at Jita, never under the market, and the schedule keeps it from now on.
        $this->assertSame([[34, 'buy'], [34, 'sell'], [35, 'sell']], array_map(fn ($row) => [$row['type_id'], $row['price_type']], $pricing->written));
        $this->assertSame(['janice'], array_values(array_unique(array_column($pricing->written, 'provider'))));
        $this->assertSame([], $pricing->bumped);
        $this->assertSame([['buyback-manager', 'jita', 'janice', 34], ['buyback-manager', 'jita', 'janice', 35]], $pricing->subscriptions);
        $this->assertSame([], $pricing->queued, 'fetched while the plugin waited, nothing queued');

        // The next read finds them kept.
        $again = $pricing->readOverride('buyback-manager', 'janice', 'jita', [34, 35]);
        $this->assertCount(1, $pricing->fetched);
        $this->assertSame(4.16, $again[34]['sell']['min']);
        $this->assertSame('janice', $again[35]['sell']['provider']);
    }

    public function test_an_item_the_provider_has_no_price_for_waits_before_it_is_asked_about_again(): void
    {
        $pricing = self::service();
        $pricing->outcome = ['prices' => self::JANICE + [99999 => null], 'priced' => [34]];

        $answer = $pricing->readOverride('buyback-manager', 'janice', 'jita', [34, 99999]);
        $this->assertSame(['buy' => null, 'sell' => null], $answer[99999]);

        Carbon::setTestNow('2026-11-17 12:20:00');
        $pricing->readOverride('buyback-manager', 'janice', 'jita', [34, 99999]);
        $this->assertCount(1, $pricing->fetched);

        Carbon::setTestNow('2026-11-17 12:23:00');
        $pricing->readOverride('buyback-manager', 'janice', 'jita', [34, 99999]);
        $this->assertCount(2, $pricing->fetched);
        $this->assertContains(99999, $pricing->fetched[1]['types']);
    }

    public function test_an_item_the_provider_failed_on_gets_the_markets_price_and_is_asked_again_next_read(): void
    {
        $pricing = self::service();
        $pricing->marketPrices['jita'][35] = self::FUZZWORK[35];
        $pricing->outcome = ['prices' => [34 => self::JANICE[34]], 'priced' => [34], 'failed' => [35], 'unanswered' => [35]];

        $answer = $pricing->readOverride('buyback-manager', 'janice', 'jita', [34, 35]);

        // The market's last price, as the price alone, and never kept as Janice's.
        $this->assertSame(11.8, $answer[35]['sell']['min']);
        $this->assertSame(10.0, $answer[35]['buy']['min']);
        $this->assertSame([34], array_values(array_unique(array_column($pricing->written, 'type_id'))));

        $pricing->outcome = ['prices' => [35 => self::JANICE[35]], 'priced' => [35]];
        $pricing->readOverride('buyback-manager', 'janice', 'jita', [34, 35]);
        $this->assertSame([35], $pricing->fetched[1]['types'], 'Tritanium was answered a moment ago');
    }

    public function test_a_fallbacks_price_answers_the_read_but_is_never_kept(): void
    {
        $pricing = self::service();
        $pricing->outcome = ['prices' => [34 => self::FUZZWORK[34]], 'priced' => [34], 'stood_in' => [34], 'unanswered' => [], 'outright' => true,
            'failed' => [34], 'fallback' => ['provider' => 'fuzzwork', 'market' => 'jita', 'label' => 'Fuzzwork']];

        $answer = $pricing->readOverride('buyback-manager', 'janice', 'jita', [34]);

        $this->assertSame(4.09, $answer[34]['sell']['min']);
        $this->assertSame([], $pricing->written);

        // Janice is asked again on the next read.
        $pricing->readOverride('buyback-manager', 'janice', 'jita', [34]);
        $this->assertCount(2, $pricing->fetched);
    }

    public function test_an_override_naming_the_markets_own_provider_reads_the_markets_prices(): void
    {
        $pricing = self::service();
        $pricing->marketPrices['jita'][34] = self::FUZZWORK[34];

        $answer = $pricing->readOverride('structure-manager', 'fuzzwork', 'jita', [34]);

        $this->assertSame(self::FUZZWORK[34], $answer[34]);
        $this->assertSame([], $pricing->fetched);
        $this->assertSame([], $pricing->subscriptions);
    }

    /** Janice prices only the hubs, and would answer for a citadel with Jita 4-4's price. */
    public function test_an_override_that_cannot_price_the_place_reads_the_markets_prices(): void
    {
        $pricing = self::service();
        $pricing->marketPrices['c-j6mt'][34] = self::FUZZWORK[34];

        $answer = $pricing->readOverride('structure-manager', 'janice', 'c-j6mt', [34], 'sell');

        $this->assertSame(self::FUZZWORK[34]['sell'], $answer[34]);
        $this->assertSame([], $pricing->fetched);
        $this->assertSame([], $pricing->subscriptions);
    }

    public function test_one_side_comes_back_as_get_prices_gives_it(): void
    {
        $pricing = self::service([], ['jita@janice' => [34 => 60, 35 => 60]]);
        $pricing->written = [self::kept('jita', 'janice', 34, 'sell', 4.16), self::kept('jita', 'janice', 35, 'sell', 12.0)];

        $sell = $pricing->readOverride('mining-manager', 'janice', 'jita', [34, 35], 'sell');
        $buy = $pricing->readOverride('mining-manager', 'janice', 'jita', [34, 35], 'buy');

        $this->assertSame(4.16, $sell[34]['min']);
        $this->assertSame('janice', $sell[35]['provider']);
        $this->assertSame([34 => null, 35 => null], $buy);
        $this->assertSame([], $pricing->fetched);
    }

    public function test_saving_an_override_subscribes_what_the_plugin_reads_there_and_queues_its_first_fetch(): void
    {
        $pricing = self::service();
        $pricing->typeSubscriptions['jita'] = [34, 35];

        $moved = $pricing->followPreferenceChange('buyback-manager', 'jita', null, 'jita', 'Janice');

        $this->assertSame(['dropped' => 0, 'subscribed' => 2], $moved);
        $this->assertSame([['buyback-manager', 'jita', 'janice', 34], ['buyback-manager', 'jita', 'janice', 35]], $pricing->subscriptions);
        $this->assertSame([['market' => 'jita', 'provider' => 'janice', 'types' => [34, 35], 'trigger' => 'subscription', 'for' => null]], $pricing->queued);
    }

    public function test_changing_an_override_drops_what_was_kept_through_the_old_provider(): void
    {
        $pricing = self::service([
            ['buyback-manager', 'jita', 'janice', 34],
            ['buyback-manager', 'amarr', 'janice', 35],
            ['market-manager', 'jita', 'janice', 34],
        ]);
        $pricing->typeSubscriptions['jita'] = [34];

        $moved = $pricing->followPreferenceChange('buyback-manager', 'jita', 'janice', 'jita', 'esi');

        $this->assertSame(['dropped' => 2, 'subscribed' => 1], $moved);
        $this->assertSame([['market-manager', 'jita', 'janice', 34], ['buyback-manager', 'jita', 'esi', 34]], $pricing->subscriptions);
    }

    public function test_resetting_or_naming_the_markets_own_provider_keeps_nothing(): void
    {
        $pricing = self::service([['buyback-manager', 'jita', 'janice', 34]]);
        $pricing->typeSubscriptions['jita'] = [34];

        $this->assertSame(['dropped' => 0, 'subscribed' => 0], $pricing->followPreferenceChange('buyback-manager', 'jita', 'janice', 'jita', 'janice'), 'nothing changed');
        $this->assertSame(['dropped' => 1, 'subscribed' => 0], $pricing->followPreferenceChange('buyback-manager', 'jita', 'janice', 'jita', 'fuzzwork'));
        $this->assertSame([], $pricing->subscriptions);
        $this->assertSame([], $pricing->queued);

        $pricing->subscriptions = [['buyback-manager', 'jita', 'janice', 34]];
        $this->assertSame(['dropped' => 1, 'subscribed' => 0], $pricing->followPreferenceChange('buyback-manager', 'jita', 'janice', 'jita', null));
    }

    public function test_diagnostics_says_what_each_override_reads(): void
    {
        $pricing = self::service();

        $health = $pricing->overrideHealth([
            (object) ['plugin_key' => 'buyback-manager', 'market' => 'jita', 'provider_override' => 'janice'],
            (object) ['plugin_key' => 'structure-manager', 'market' => 'c-j6mt', 'provider_override' => 'esi'],
            (object) ['plugin_key' => 'mining-manager', 'market' => 'c-j6mt', 'provider_override' => 'janice'],
            (object) ['plugin_key' => 'hr-manager', 'market' => 'jita', 'provider_override' => null],
        ]);

        $this->assertSame(['buyback-manager' => 'kept', 'structure-manager' => 'own', 'mining-manager' => 'cannot_price'],
            array_column($health, 'reads', 'plugin'));
        $this->assertStringContainsString('Jita 4-4', $health[2]['reason']);
        $this->assertSame('Janice', $health[0]['provider_label']);
    }

    public function test_a_price_fetched_during_a_read_comes_back_as_it_does_once_kept(): void
    {
        $pricing = self::service();
        $pricing->outcome = ['prices' => self::JANICE, 'priced' => [34, 35]];

        $first = $pricing->readOverride('buyback-manager', 'janice', 'jita', [34, 35]);
        $again = $pricing->readOverride('buyback-manager', 'janice', 'jita', [34, 35]);

        $this->assertCount(1, $pricing->fetched);
        $this->assertEquals($again, $first);
        $this->assertSame('2026-11-17 12:07:00', $first[34]['sell']['updated_at']->format('Y-m-d H:i:s'));
        $this->assertSame('janice', $first[35]['sell']['strategy'], 'what Janice is stored with');
    }

    public function test_the_preference_a_plugin_is_shown_names_the_provider_that_answers_its_reads(): void
    {
        $pricing = self::service();
        $pref = fn (string $market, ?string $override) => (object) ['market' => $market, 'price_type' => 'sell', 'admin_overridden' => 1, 'provider_override' => $override];

        $kept = $pricing->describePreference($pref('jita', 'Janice'));
        $own = $pricing->describePreference($pref('c-j6mt', 'esi'));
        $cannot = $pricing->describePreference($pref('c-j6mt', 'janice'));
        $gone = $pricing->describePreference($pref('gone', 'janice'));
        $none = $pricing->describePreference($pref('amarr', ''));

        $shown = fn (array $p) => [$p['provider_override'], $p['override_reads'], $p['effective_provider'], $p['provider_label'], $p['market_provider'], $p['market_name']];
        $this->assertSame(['janice', 'kept', 'janice', 'Janice', 'fuzzwork', 'Jita'], $shown($kept));
        $this->assertSame(['esi', 'own', 'esi', 'MCPraisal', 'esi', 'C-J6MT'], $shown($own));
        // Janice would price the citadel at Jita 4-4, so the citadel's own provider answers.
        $this->assertSame(['janice', 'cannot_price', 'esi', 'MCPraisal', 'esi', 'C-J6MT'], $shown($cannot));
        // A market Manager Core doesn't have is asked through the override on every read.
        $this->assertSame(['janice', 'live', 'janice', 'Janice', 'fuzzwork', 'gone'], $shown($gone));
        $this->assertSame([null, null, 'janice', 'Janice', 'janice', 'Amarr'], $shown($none));

        $this->assertSame('esi', $cannot['provider'], 'the old key says the same');
        $this->assertTrue($kept['admin_overridden']);
        $this->assertSame('sell', $kept['price_type']);
    }

    /** An order book for MCPraisal, whose own fetch is replaced by these orders. */
    private static function esi(): MarketDataService
    {
        return new class extends MarketDataService {
            public array $saved = [];
            public array $history = [];
            public array $orders = [];
            public bool $fails = false;

            public function __construct()
            {
            }

            public function updateMarketPrices(array $typeIds, $market = 'jita')
            {
                if ($this->fails) {
                    throw new \RuntimeException('ESI went away');
                }
                foreach ($typeIds as $typeId) {
                    if (isset($this->orders[$typeId])) {
                        $this->calculateAndSavePrices($typeId, $this->orders[$typeId], $market);
                    }
                }
            }

            public function isStructureMarket(string $market): bool
            {
                return $market === 'c-j6mt';
            }

            protected function savePriceStats($typeId, $market, $priceType, array $stats)
            {
                $this->saved[] = [$typeId, $market, $priceType];
            }

            protected function updatePriceHistory($typeId, $market, array $buyOrders, array $sellOrders)
            {
                $this->history[] = $typeId;
            }
        };
    }

    private const ORDERS = [
        34 => [['is_buy_order' => true, 'price' => 3.92, 'volume_remain' => 1000], ['is_buy_order' => false, 'price' => 4.16, 'volume_remain' => 500]],
        35 => [['is_buy_order' => false, 'price' => 12.0, 'volume_remain' => 10]],
    ];

    public function test_mcpraisal_works_a_hub_out_without_storing_it(): void
    {
        $esi = self::esi();
        $esi->orders = self::ORDERS;

        $collected = $esi->collectMarketPrices([34, 35, 36], 'jita');

        $this->assertSame([], $esi->saved);
        $this->assertSame([], $esi->history, 'nor the day\'s history');
        $this->assertSame([34, 35], array_keys($collected));
        $this->assertSame(3.92, $collected[34]['buy']['max']);
        $this->assertSame(4.16, $collected[34]['sell']['min']);
        $this->assertNull($collected[35]['buy']);
        $this->assertSame('esi', PricingService::providerOfStrategy($collected[35]['sell']['strategy']));

        // The next fetch is the market's own again, and stores what it reads.
        $esi->updateMarketPrices([34], 'jita');
        $this->assertSame([[34, 'jita', 'buy'], [34, 'jita', 'sell']], $esi->saved);
        $this->assertSame([34], $esi->history);
    }

    public function test_mcpraisal_stores_again_after_a_fetch_that_stores_nothing_fails(): void
    {
        $esi = self::esi();
        $esi->orders = self::ORDERS;
        $esi->fails = true;

        try {
            $esi->collectMarketPrices([34], 'jita');
            $this->fail('ESI went away');
        } catch (\RuntimeException $e) {
            $esi->fails = false;
        }

        $esi->updateMarketPrices([34], 'jita');
        $this->assertCount(2, $esi->saved);
    }

    public function test_mcpraisal_answers_a_hub_it_does_not_store_with_what_it_worked_out(): void
    {
        $esi = self::esi();
        $esi->orders = self::ORDERS;
        $provider = new class ($esi) extends ESIPriceProvider {
            public function __construct(MarketDataService $service)
            {
                $this->marketDataService = $service;
            }
        };

        $answer = $provider->getPrices([34, '36'], 'jita', false);

        $this->assertSame([34, 36], array_keys($answer));
        $this->assertSame(4.16, $answer[34]['sell']['min']);
        $this->assertNull($answer[36]);
        $this->assertSame([], $esi->saved);
    }
}
