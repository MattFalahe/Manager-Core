<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\AppraisalService;
use ManagerCore\Services\PriceProviders\PriceProviderInterface;
use ManagerCore\Services\PricingService;
use PHPUnit\Framework\TestCase;

/**
 * A provider picked for one appraisal or one Diagnostics test answers that
 * caller alone. The market's rows are what every plugin reading the market
 * gets, so only the market's own provider stores what it fetches.
 */
class ProviderAloneTest extends TestCase
{
    /** Tritanium and Pyerite at Jita 4-4 on 8 November, through Janice. */
    private const JANICE = [
        34 => ['buy' => ['min' => 3.92, 'max' => 3.92], 'sell' => ['min' => 4.16, 'max' => 4.16]],
        35 => ['buy' => null, 'sell' => ['min' => 12.0, 'max' => 12.0]],
    ];

    private static function provider(array $answer, ?array $fresh = null, bool $available = true): PriceProviderInterface
    {
        return new class ($answer, $fresh, $available) implements PriceProviderInterface {
            public array $asked = [];

            public function __construct(private array $answer, private ?array $fresh, private bool $available)
            {
            }

            public function getPrices(array $typeIds, string $market, bool $persist = true): array
            {
                $this->asked[] = ['types' => $typeIds, 'market' => $market, 'persist' => $persist];

                return array_intersect_key($this->answer, array_flip($typeIds)) + array_fill_keys($typeIds, null);
            }

            public function lastFetchFailures(): array
            {
                return ['type_ids' => [], 'error' => null, 'retryable' => false, 'status' => null];
            }

            public function lastFreshTypeIds(): ?array
            {
                return $this->fresh;
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

    /** A PricingService whose markets route as given, asking only the provider handed in. */
    private static function service(PriceProviderInterface $provider, array $routing): PricingService
    {
        return new class ($provider, $routing) extends PricingService {
            public array $bumped = [];

            public function __construct(private PriceProviderInterface $provider, private array $routing)
            {
            }

            protected function getPriceProvider(?string $override = null)
            {
                return $this->provider;
            }

            protected function resolveMarketProvider(string $marketKey): ?string
            {
                return $this->routing[$marketKey] ?? null;
            }

            protected function bumpPriceCacheVersion(string $market): void
            {
                $this->bumped[] = $market;
            }
        };
    }

    public function test_another_providers_answer_is_not_stored_under_the_market(): void
    {
        $janice = self::provider(self::JANICE);
        $pricing = self::service($janice, ['jita' => 'fuzzwork']);

        $answer = $pricing->pricesFromProvider('jita', [34, 35], 'janice');

        $this->assertFalse($janice->asked[0]['persist']);
        $this->assertSame([], $pricing->bumped);
        $this->assertSame(4.16, $answer[34]['sell']['min']);
        $this->assertNull($answer[35]['buy']);
    }

    public function test_the_markets_own_provider_stores_as_a_refresh_would(): void
    {
        $janice = self::provider(self::JANICE);
        $pricing = self::service($janice, ['jita' => 'janice']);

        $pricing->pricesFromProvider('jita', [34], 'janice');

        $this->assertTrue($janice->asked[0]['persist']);
        $this->assertSame(['jita'], $pricing->bumped);
    }

    /** A market with no provider on its row is priced by Fuzzwork. */
    public function test_a_market_with_no_provider_set_is_fuzzworks_own(): void
    {
        $fuzzwork = self::provider(self::JANICE);
        $pricing = self::service($fuzzwork, []);

        $pricing->pricesFromProvider('jita', [34], 'fuzzwork');

        $this->assertTrue($fuzzwork->asked[0]['persist']);
    }

    /** MCPraisal works a hub's order book out without storing it now, like the others. */
    public function test_mcpraisal_stores_only_at_a_market_of_its_own(): void
    {
        $esi = self::provider(self::JANICE, [34, 35]);
        $pricing = self::service($esi, ['jita' => 'fuzzwork', 'c-j6mt' => 'esi']);

        $pricing->pricesFromProvider('jita', [34], 'esi');
        $this->assertFalse($esi->asked[0]['persist']);
        $this->assertSame([], $pricing->bumped);

        $pricing->pricesFromProvider('c-j6mt', [34], 'esi');
        $this->assertTrue($esi->asked[1]['persist']);
        $this->assertSame(['c-j6mt'], $pricing->bumped);
    }

    /** MCPraisal reads its answer back from the cache, old prices included. */
    public function test_only_what_mcpraisal_fetched_now_is_its_answer(): void
    {
        $esi = self::provider(self::JANICE, [35]);
        $pricing = self::service($esi, ['jita' => 'esi']);

        $answer = $pricing->pricesFromProvider('jita', [34, 35], 'esi');

        $this->assertNull($answer[34]);
        $this->assertSame(12.0, $answer[35]['sell']['min']);
    }

    public function test_a_provider_that_is_not_available_is_not_asked(): void
    {
        $janice = self::provider(self::JANICE, null, false);
        $pricing = self::service($janice, ['jita' => 'fuzzwork']);

        $this->assertSame([], $pricing->pricesFromProvider('jita', [34], 'janice'));
        $this->assertSame([], $janice->asked);
    }

    public function test_the_same_type_is_asked_for_once(): void
    {
        $janice = self::provider(self::JANICE);
        $pricing = self::service($janice, ['jita' => 'fuzzwork']);

        $pricing->pricesFromProvider('jita', ['34', 34, 35], 'janice');

        $this->assertSame([34, 35], $janice->asked[0]['types']);
        $this->assertSame([], self::service($janice, [])->pricesFromProvider('jita', [], 'janice'));
    }

    /** An updatePrices call naming a provider asks it alone too. */
    public function test_update_prices_with_a_provider_named_stores_nothing_for_another_provider(): void
    {
        $janice = self::provider(self::JANICE);
        $pricing = self::service($janice, ['jita' => 'fuzzwork']);

        $pricing->updatePrices('jita', [34, 35], 'janice');

        $this->assertCount(1, $janice->asked);
        $this->assertFalse($janice->asked[0]['persist']);
        $this->assertSame([], $pricing->bumped);
    }

    public function test_an_appraisal_takes_each_side_the_picked_provider_priced(): void
    {
        $cached = [
            34 => ['buy' => ['min' => 3.50, 'max' => 3.93], 'sell' => ['min' => 3.50, 'max' => 9.0]],
            35 => ['buy' => ['min' => 9.0, 'max' => 10.0], 'sell' => null],
        ];

        $prices = AppraisalService::layProviderPrices($cached, self::JANICE);

        $this->assertSame(3.92, $prices[34]['buy']['max']);
        $this->assertSame(4.16, $prices[34]['sell']['min']);
        // Janice had no buy price for Pyerite: the cached one stays, as it did
        // when Janice stored its answer and the appraisal read the rows back.
        $this->assertSame(10.0, $prices[35]['buy']['max']);
        $this->assertSame(12.0, $prices[35]['sell']['min']);
    }

    public function test_an_appraisal_keeps_the_cached_price_where_the_provider_gave_none(): void
    {
        $cached = [34 => ['buy' => ['max' => 3.93], 'sell' => ['min' => 4.09]], 36 => null];

        $prices = AppraisalService::layProviderPrices($cached, [34 => null, 36 => ['buy' => null, 'sell' => null]]);

        $this->assertSame($cached, $prices);
    }

    public function test_an_appraisal_takes_a_price_the_market_never_had(): void
    {
        $prices = AppraisalService::layProviderPrices([35 => null], [35 => self::JANICE[35]]);

        $this->assertSame(['buy' => null, 'sell' => ['min' => 12.0, 'max' => 12.0]], $prices[35]);
    }
}
