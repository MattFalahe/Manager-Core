<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\PricingService;
use ManagerCore\Tests\ArrayCache;
use ManagerCore\Tests\ArrayLog;
use ManagerCore\Topics;
use PHPUnit\Framework\TestCase;

/**
 * Once a refresh has stored prices for a market, Manager Core says so in
 * pricing.market_refreshed, so a plugin with its own copy of the prices
 * reads them then rather than on a timer of its own.
 */
class MarketRefreshedTest extends TestCase
{
    protected function setUp(): void
    {
        ArrayCache::$items = [];
        ArrayLog::$lines = [];
    }

    /**
     * The real refresh, with the provider's answer, the table reads and the
     * event bus replaced.
     *
     * @param int[] $priced the types the provider prices
     */
    private static function service(array $priced, array $subscribers = ['mining-manager', 'structure-manager']): PricingService
    {
        return new class ($priced, $subscribers) extends PricingService {
            public array $published = [];
            public array $askedSubscribersOf = [];
            public bool $busFails = false;

            public function __construct(private array $priced, private array $subscribers)
            {
            }

            public function waiting(string $market): void
            {
                $this->refreshWaiting($market);
            }

            protected function fetchWithFallback(string $providerKey, string $market, array $typeIds, bool $persist): array
            {
                return ['prices' => [], 'asked' => count($typeIds), 'failed' => [], 'unanswered' => [], 'stood_in' => [],
                    'priced' => array_values(array_intersect($typeIds, $this->priced)), 'provider' => 'Fuzzwork', 'error' => null,
                    'retryable' => false, 'status' => null, 'outright' => false, 'fallback' => null];
            }

            protected function recordMarketOutcome(string $market, array $outcome, bool $retrying): void
            {
            }

            protected function bumpPriceCacheVersion(string $market): void
            {
            }

            protected function withWaitingLock(string $market, callable $do): void
            {
                $do();
            }

            protected function subscribersOf(string $market, array $typeIds): array
            {
                $this->askedSubscribersOf[] = [$market, $typeIds];

                return $this->subscribers;
            }

            protected function publishRefreshed(array $payload): void
            {
                if ($this->busFails) {
                    throw new \RuntimeException('Redis is away');
                }
                $this->published[] = $payload;
            }
        };
    }

    public function test_the_topic_is_registered_as_manager_cores_own(): void
    {
        $topic = Topics::describe('pricing.market_refreshed');

        $this->assertSame('manager-core', $topic['publisher']);
        $this->assertSame(['market', 'refreshed_at'], $topic['required']);
        $this->assertSame('pricing.market_refreshed:{market}:{refreshed_at}', $topic['idempotency_template']);
    }

    public function test_a_refresh_that_stored_prices_says_so_once(): void
    {
        $pricing = self::service([34, 35]);

        $pricing->updatePrices('jita', [34, 35, 36]);

        $this->assertCount(1, $pricing->published);
        $event = $pricing->published[0];
        $this->assertSame(['market', 'refreshed_at', 'plugins', 'type_count', 'stored_count', 'trigger'], array_keys($event));
        $this->assertSame('jita', $event['market']);
        $this->assertSame(['mining-manager', 'structure-manager'], $event['plugins']);
        $this->assertSame(3, $event['type_count']);
        $this->assertSame(2, $event['stored_count']);
        $this->assertSame('schedule', $event['trigger']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/', $event['refreshed_at']);
    }

    /** The subscribers named are those of the types that got a price, not of everything asked. */
    public function test_the_plugins_named_are_the_subscribers_of_what_was_stored(): void
    {
        $pricing = self::service([35]);

        $pricing->updatePrices('jita', [34, 35, 36]);

        $this->assertSame([['jita', [35]]], $pricing->askedSubscribersOf);
    }

    public function test_a_refresh_that_stored_nothing_says_nothing(): void
    {
        $pricing = self::service([]);

        $pricing->updatePrices('jita', [34, 35]);

        $this->assertSame([], $pricing->published);
    }

    public function test_each_way_a_refresh_starts_is_named(): void
    {
        $pricing = self::service([34]);

        $pricing->updatePrices('jita', [34], null, PricingService::TRIGGER_SUBSCRIPTION);
        $pricing->updatePrices('jita', [34], null, PricingService::TRIGGER_MANUAL);
        $pricing->retryPrices('jita', [34]);
        ArrayCache::$items['mc.pricing.refresh_waiting.jita'] = [34];
        $pricing->waiting('jita');

        $this->assertSame(['subscription', 'manual', 'retry', 'subscription'], array_column($pricing->published, 'trigger'));
    }

    /** Two refreshes of one market in the same second are two events, so the time is in the key to the microsecond. */
    public function test_two_refreshes_in_a_row_do_not_share_a_key(): void
    {
        $pricing = self::service([34]);

        $pricing->updatePrices('jita', [34]);
        $pricing->updatePrices('jita', [34]);

        $this->assertNotSame($pricing->published[0]['refreshed_at'], $pricing->published[1]['refreshed_at']);
    }

    /** The prices are stored whatever happens to the event. */
    public function test_a_bus_that_is_down_does_not_fail_the_refresh(): void
    {
        $pricing = self::service([34]);
        $pricing->busFails = true;

        $pricing->updatePrices('jita', [34]);

        $this->assertSame([], $pricing->published);
        $this->assertStringContainsString('Could not announce', end(ArrayLog::$lines)[1]);
    }

    /** A provider picked for one appraisal or test is nobody's refresh. */
    public function test_a_provider_asked_alone_announces_nothing(): void
    {
        $pricing = new class extends PricingService {
            public array $published = [];

            protected function askProviderAlone(string $market, array $typeIds, string $providerKey): array
            {
                return [];
            }

            protected function publishRefreshed(array $payload): void
            {
                $this->published[] = $payload;
            }
        };

        $pricing->updatePrices('jita', [34], 'janice');

        $this->assertSame([], $pricing->published);
    }
}
