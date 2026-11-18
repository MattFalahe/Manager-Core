<?php

namespace ManagerCore\Tests\Unit;

use Carbon\Carbon;
use ManagerCore\Services\PricingService;
use ManagerCore\Tests\ArrayCache;
use ManagerCore\Tests\ArrayLog;
use PHPUnit\Framework\TestCase;

/**
 * A plugin whose users watch a price move may ask for the types it
 * subscribes to be fetched again between the scheduled refreshes. Only a
 * listed plugin, only its own types, and no more often than the market's
 * provider allows. Every other plugin stays on the scheduled refresh.
 */
class RefreshOnRequestTest extends TestCase
{
    private const ASKED = 'mc.pricing.refresh_asked.';
    private const REFRESHING = 'mc.pricing.refreshing.';

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

    /**
     * The real ask and the real refresh, with the tables, the queue, the
     * provider and the event bus replaced.
     *
     * @param array<int, int> $ages       seconds since each type's price was stored; a type left out has none
     * @param int[]           $subscribed the types the asking plugin is subscribed to
     */
    private static function service(array $ages, array $subscribed = [34, 35, 36], string $provider = 'fuzzwork'): PricingService
    {
        return new class ($ages, $subscribed, $provider) extends PricingService {
            /** @var array<int, array{market: string, types: int[], for: string}> */
            public array $queued = [];

            /** @var array<int, array{market: string, types: int[]}> */
            public array $fetched = [];

            public array $published = [];
            public array $retried = [];
            public int $scheduledNotes = 0;
            public bool $providerThrows = false;

            /** @var int[] the types the provider prices; null for all of them */
            public ?array $priced = null;

            public function __construct(public array $ages, private array $subscribed, private string $provider)
            {
            }

            protected function effectiveMarkets(bool $enabledOnly): array
            {
                // Hek is left out, as a hub someone disabled.
                return array_fill_keys(['jita', 'amarr', 'dodixie', 'rens'], ['provider' => $this->provider]);
            }

            protected function resolveMarketProvider(string $marketKey): ?string
            {
                return $this->provider;
            }

            protected function subscribedTypeIds(string $pluginName, string $market, array $typeIds): array
            {
                return array_values(array_intersect($typeIds, $this->subscribed));
            }

            protected function typeIdsOlderThan(string $market, array $typeIds, int $seconds): array
            {
                return array_values(array_filter($typeIds, fn ($id) => ($this->ages[$id] ?? PHP_INT_MAX) > $seconds));
            }

            protected function dispatchRequested(string $market, array $typeIds, string $pluginName): void
            {
                $this->queued[] = ['market' => $market, 'types' => $typeIds, 'for' => $pluginName];
            }

            public function getSubscribedTypes($market = null)
            {
                $subscribed = $this->subscribed;

                return [$market => new class ($subscribed) {
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
                if ($this->providerThrows) {
                    throw new \RuntimeException('The provider went away');
                }
                $this->fetched[] = ['market' => $market, 'types' => $typeIds];

                return ['prices' => [], 'asked' => count($typeIds), 'failed' => [], 'unanswered' => [], 'stood_in' => [],
                    'priced' => $this->priced === null ? $typeIds : array_values(array_intersect($typeIds, $this->priced)),
                    'provider' => 'Fuzzwork', 'error' => null, 'retryable' => false, 'status' => null, 'outright' => false,
                    'fallback' => null];
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
            }

            protected function subscribersOf(string $market, array $typeIds): array
            {
                return ['market-manager', 'mining-manager'];
            }

            protected function publishRefreshed(array $payload): void
            {
                $this->published[] = $payload;
            }

            protected function noteScheduledRefresh(): void
            {
                $this->scheduledNotes++;
            }
        };
    }

    public function test_only_market_manager_is_listed_to_ask(): void
    {
        $this->assertSame(['market-manager'], config('manager-core.pricing.refresh_on_request.plugins'));
    }

    public function test_a_plugin_that_is_not_listed_gets_nothing_fetched(): void
    {
        $pricing = self::service([34 => 99999]);

        $answer = $pricing->refreshIfOlder('mining-manager', [34], 'jita', 3600);

        $this->assertSame(['queued' => 0, 'fresh' => 0, 'reason' => 'not_allowed'], $answer);
        $this->assertSame([], $pricing->queued);
    }

    public function test_a_market_that_is_not_enabled_is_refused(): void
    {
        $pricing = self::service([34 => 99999]);

        $answer = $pricing->refreshIfOlder('market-manager', [34], 'hek', 3600);

        $this->assertSame('unknown_market', $answer['reason']);
        $this->assertSame([], $pricing->queued);
    }

    /** Nothing is fetched that the plugin did not subscribe: the ask can't be used to price anything else. */
    public function test_only_the_types_the_plugin_subscribes_to_are_fetched(): void
    {
        $pricing = self::service([34 => 99999, 587 => 99999], [34]);

        $answer = $pricing->refreshIfOlder('market-manager', [34, 587], 'jita', 3600);

        $this->assertSame(['queued' => 1, 'fresh' => 0, 'reason' => null], $answer);
        $this->assertSame([['market' => 'jita', 'types' => [34], 'for' => 'market-manager']], $pricing->queued);

        $this->assertSame('not_subscribed', self::service([587 => 99999], [34])->refreshIfOlder('market-manager', [587], 'jita', 3600)['reason']);
    }

    public function test_what_is_older_than_asked_is_queued_and_the_rest_counted_fresh(): void
    {
        $pricing = self::service([34 => 4000, 35 => 100]);

        $answer = $pricing->refreshIfOlder('market-manager', [34, 35, 36, '34'], 'jita', 3600);

        // 34 is older than the hour, 36 has no price at all, 35 was stored 100 seconds ago.
        $this->assertSame(['queued' => 2, 'fresh' => 1, 'reason' => null], $answer);
        $this->assertSame([['market' => 'jita', 'types' => [34, 36], 'for' => 'market-manager']], $pricing->queued);
    }

    /** The usual ask: four times an hour, with nothing older than the hour. */
    public function test_an_ask_that_finds_nothing_old_queues_nothing(): void
    {
        $pricing = self::service([34 => 900, 35 => 900, 36 => 900]);

        $answer = $pricing->refreshIfOlder('market-manager', [34, 35, 36], 'jita', 3600);

        $this->assertSame(['queued' => 0, 'fresh' => 3, 'reason' => null], $answer);
        $this->assertSame([], $pricing->queued);
        $this->assertSame([], ArrayCache::$items);
    }

    public function test_no_types_is_no_ask(): void
    {
        $this->assertSame(['queued' => 0, 'fresh' => 0, 'reason' => null], self::service([])->refreshIfOlder('market-manager', [], 'jita', 3600));
    }

    /** A plugin can't ask its way past what the provider is good for. */
    public function test_the_provider_sets_the_youngest_price_that_is_fetched_again(): void
    {
        // 20 minutes old, asked for with a one minute limit.
        $fuzzwork = self::service([34 => 1200]);
        $this->assertSame(0, $fuzzwork->refreshIfOlder('market-manager', [34], 'jita', 60)['queued']);

        // 40 minutes old: past Fuzzwork's half hour, inside Janice's hour.
        $fuzzwork = self::service([34 => 2400]);
        $this->assertSame(1, $fuzzwork->refreshIfOlder('market-manager', [34], 'jita', 60)['queued']);

        // Each case on a market of its own: Fuzzwork's ask above is remembered at Jita.
        $janice = self::service([34 => 2400], [34], 'janice');
        $this->assertSame(0, $janice->refreshIfOlder('market-manager', [34], 'amarr', 60)['queued']);
        $janice = self::service([34 => 3700], [34], 'janice');
        $this->assertSame(1, $janice->refreshIfOlder('market-manager', [34], 'dodixie', 60)['queued']);

        // A provider with no wait of its own gets the longest.
        $seat = self::service([34 => 2400], [34], 'seat');
        $this->assertSame(0, $seat->refreshIfOlder('market-manager', [34], 'rens', 60)['queued']);
    }

    /**
     * Market Manager asks every 15 minutes for prices under an hour old.
     * Each fetch lands seconds after the ask, so without the tolerance the
     * same look an hour later found the price a few seconds too young.
     *
     * @return string[] when each fetch landed
     */
    private static function askEveryQuarter(string $provider, int $maxAge, int $hours): array
    {
        // Each run starts the clock again, so it starts the cache again too.
        ArrayCache::$items = [];
        $pricing = self::service([34 => 99999], [34], $provider);
        $landed = [];
        $at = Carbon::parse('2026-11-17 00:07:00');

        for ($look = 0; $look < $hours * 4; $look++, $at = $at->copy()->addMinutes(15)) {
            // A run reaches its ask a few seconds in, and not always the same few.
            Carbon::setTestNow($at->copy()->addSeconds($look % 4 === 0 ? 9 : 2));
            if ($pricing->refreshIfOlder('market-manager', [34], 'jita', $maxAge)['queued'] > 0) {
                // The worker stores the price a few seconds on.
                $pricing->ages[34] = -8;
                $landed[] = $at->format('H:i');
            }
            $pricing->ages[34] += 900;
        }

        return $landed;
    }

    public function test_asking_every_quarter_for_hourly_prices_gets_one_an_hour(): void
    {
        $hourly = ['00:07', '01:07', '02:07', '03:07', '04:07'];

        $this->assertSame($hourly, self::askEveryQuarter('fuzzwork', 3600, 5));
        $this->assertSame($hourly, self::askEveryQuarter('janice', 3600, 5));
        $this->assertSame(['00:07', '00:37', '01:07', '01:37'], self::askEveryQuarter('fuzzwork', 900, 2), 'Fuzzwork\'s half hour, asked for 15 minutes');
    }

    /** An item nobody sells never gets a new price, and used to look old on every ask. */
    public function test_a_type_asked_for_is_not_asked_for_again_until_the_providers_wait_is_over(): void
    {
        $pricing = self::service([34 => 4000]);

        $this->assertSame(2, $pricing->refreshIfOlder('market-manager', [34, 36], 'jita', 3600)['queued']);

        Carbon::setTestNow('2026-11-17 12:22:00');
        $again = $pricing->refreshIfOlder('market-manager', [34, 36], 'jita', 3600);
        $this->assertSame(['queued' => 0, 'fresh' => 0, 'reason' => null], $again);
        $this->assertCount(1, $pricing->queued);

        // Half an hour and a minute after the first ask.
        Carbon::setTestNow('2026-11-17 12:38:00');
        $this->assertSame(2, $pricing->refreshIfOlder('market-manager', [34, 36], 'jita', 3600)['queued']);
        $this->assertCount(2, $pricing->queued);
    }

    public function test_a_type_first_old_at_a_later_ask_is_still_queued_then(): void
    {
        $pricing = self::service([34 => 4000, 35 => 3000]);

        $pricing->refreshIfOlder('market-manager', [34, 35], 'jita', 3600);

        Carbon::setTestNow('2026-11-17 12:22:00');
        $pricing->ages[35] = 3900;
        $pricing->refreshIfOlder('market-manager', [34, 35], 'jita', 3600);

        $this->assertSame([[34], [35]], array_column($pricing->queued, 'types'));
    }

    /** The job waited on the queue while the scheduled refresh ran. */
    public function test_the_fetch_leaves_out_what_got_a_price_while_it_waited(): void
    {
        $pricing = self::service([34 => 4000, 35 => 20]);

        $pricing->refreshRequested('jita', [34, 35], 'market-manager');

        $this->assertSame([['market' => 'jita', 'types' => [34]]], $pricing->fetched);

        $pricing = self::service([34 => 20, 35 => 20]);
        $pricing->refreshRequested('jita', [34, 35], 'market-manager');
        $this->assertSame([], $pricing->fetched);
        $this->assertSame([], $pricing->published);
    }

    /** Mining Manager copies its prices when a refresh names it, and this one is not for it. */
    public function test_the_fetch_is_announced_to_the_plugin_that_asked_and_no_other(): void
    {
        $pricing = self::service([34 => 4000]);

        $pricing->refreshRequested('jita', [34], 'market-manager');

        $this->assertCount(1, $pricing->published);
        $this->assertSame(['market-manager'], $pricing->published[0]['plugins']);
        $this->assertSame('request', $pricing->published[0]['trigger']);
    }

    /** The plugin asks again by itself, so a failed type is not queued for another try as well. */
    public function test_the_fetch_queues_no_try_of_its_own_for_what_failed(): void
    {
        $pricing = self::service([34 => 4000]);

        $pricing->refreshRequested('jita', [34], 'market-manager');
        $this->assertSame([], $pricing->retried);

        $pricing->updatePrices('jita', [34]);
        $this->assertSame(['jita'], $pricing->retried);
    }

    public function test_an_ask_gives_way_to_a_refresh_already_running_and_may_ask_again(): void
    {
        $pricing = self::service([34 => 4000]);
        $pricing->refreshIfOlder('market-manager', [34], 'jita', 3600);
        ArrayCache::$items[self::REFRESHING . 'jita'] = 'the scheduled refresh';

        $pricing->refreshRequested('jita', [34], 'market-manager');

        $this->assertSame([], $pricing->fetched);
        $this->assertSame('the scheduled refresh', ArrayCache::$items[self::REFRESHING . 'jita']);
        $this->assertSame([], ArrayCache::$items[self::ASKED . 'jita']);

        // The next ask, with the other refresh over and 34 still old.
        unset(ArrayCache::$items[self::REFRESHING . 'jita']);
        $this->assertSame(1, $pricing->refreshIfOlder('market-manager', [34], 'jita', 3600)['queued']);
    }

    /** Only an ask gives way. The scheduled refresh is never skipped for one. */
    public function test_every_other_refresh_runs_whatever_is_running(): void
    {
        $pricing = self::service([]);
        ArrayCache::$items[self::REFRESHING . 'jita'] = 'an ask';

        $pricing->updatePrices('jita', null);
        $pricing->updatePrices('jita', [34], null, PricingService::TRIGGER_SUBSCRIPTION);
        $pricing->retryPrices('jita', [34]);

        $this->assertCount(3, $pricing->fetched);
        $this->assertSame('an ask', ArrayCache::$items[self::REFRESHING . 'jita'], 'the mark is the other refresh\'s to clear');
    }

    public function test_a_market_stops_counting_as_refreshing_when_the_refresh_ends_or_fails(): void
    {
        $pricing = self::service([]);

        $pricing->updatePrices('jita', [34]);
        $this->assertArrayNotHasKey(self::REFRESHING . 'jita', ArrayCache::$items);

        $pricing->providerThrows = true;
        try {
            $pricing->updatePrices('jita', [34]);
            $this->fail('the provider threw');
        } catch (\RuntimeException $e) {
            $this->assertArrayNotHasKey(self::REFRESHING . 'jita', ArrayCache::$items);
        }
    }

    /** What the Watchdog reads: asks in between must not look like the schedule running. */
    public function test_only_a_scheduled_refresh_of_everything_that_stored_a_price_is_noted(): void
    {
        $pricing = self::service([34 => 4000]);

        $pricing->refreshRequested('jita', [34], 'market-manager');
        $pricing->updatePrices('jita', [34]);
        $pricing->updatePrices('jita', [34], null, PricingService::TRIGGER_MANUAL);
        $pricing->updatePrices('jita', null, null, PricingService::TRIGGER_MANUAL);
        $pricing->retryPrices('jita', [34]);
        $this->assertSame(0, $pricing->scheduledNotes);

        $pricing->priced = [];
        $pricing->updatePrices('jita', null);
        $this->assertSame(0, $pricing->scheduledNotes, 'a run that stored nothing');

        $pricing->priced = null;
        $pricing->updatePrices('jita', null);
        $this->assertSame(1, $pricing->scheduledNotes);
    }
}
