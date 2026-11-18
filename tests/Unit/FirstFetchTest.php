<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\PricingService;
use ManagerCore\Tests\ArrayCache;
use ManagerCore\Tests\ArrayLog;
use PHPUnit\Framework\TestCase;

/**
 * A plugin that subscribes types gets their first prices fetched at once,
 * one job per market every 30 seconds. A subscribe inside those 30 seconds
 * is not dropped: its types wait, and one more fetch at the end of the 30
 * seconds asks for the types that waited, and for no others.
 */
class FirstFetchTest extends TestCase
{
    private const WINDOW = 'mc.pricing.refresh_dispatch.';
    private const WAITING = 'mc.pricing.refresh_waiting.';

    protected function setUp(): void
    {
        ArrayCache::$items = [];
        ArrayLog::$lines = [];
    }

    private static function service(): PricingService
    {
        return new class extends PricingService {
            /** @var array<int, array{market: string, types: int[], waiting: bool, delay: int}> */
            public array $queued = [];

            /** @var array<int, array{market: string, types: int[], retry: bool}> */
            public array $refreshed = [];

            /** How many times the waiting types were locked. */
            public int $locks = 0;

            public bool $lockFails = false;

            public function ask(string $market, array $typeIds): void
            {
                $this->queueFirstFetch($market, $typeIds);
            }

            protected function dispatchRefresh(string $market, array $typeIds, bool $waiting = false, int $delaySeconds = 0): void
            {
                $this->queued[] = ['market' => $market, 'types' => $typeIds, 'waiting' => $waiting, 'delay' => $delaySeconds];
            }

            protected function withWaitingLock(string $market, callable $do): void
            {
                $this->locks++;
                if ($this->lockFails) {
                    throw new \Illuminate\Contracts\Cache\LockTimeoutException();
                }
                $do();
            }

            protected function refreshMarket(string $market, $typeIds, ?string $providerOverride, bool $mayRetry,
                string $trigger = self::TRIGGER_SCHEDULE, ?string $for = null): void
            {
                $this->refreshed[] = ['market' => $market, 'types' => $typeIds, 'retry' => $mayRetry, 'trigger' => $trigger];
            }
        };
    }

    public function test_the_first_ask_is_fetched_at_once(): void
    {
        $pricing = self::service();

        $pricing->ask('jita', [34, 35]);

        $this->assertSame([['market' => 'jita', 'types' => [34, 35], 'waiting' => false, 'delay' => 0]], $pricing->queued);
        $this->assertArrayNotHasKey(self::WAITING . 'jita', ArrayCache::$items);
    }

    /** Two watches added a few seconds apart: the second used to wait for the scheduled refresh. */
    public function test_an_ask_inside_the_window_gets_a_fetch_at_the_end_of_it(): void
    {
        $pricing = self::service();

        $pricing->ask('jita', [34]);
        $pricing->ask('jita', [35]);

        $this->assertCount(2, $pricing->queued);
        $this->assertSame(
            ['market' => 'jita', 'types' => [], 'waiting' => true, 'delay' => PricingService::DEBOUNCE_WINDOW_SECONDS],
            $pricing->queued[1]
        );
        $this->assertSame([35], ArrayCache::$items[self::WAITING . 'jita']);
    }

    public function test_the_end_of_window_fetch_asks_for_the_types_that_waited_and_no_others(): void
    {
        $pricing = self::service();

        $pricing->ask('jita', [34]);
        $pricing->ask('jita', [35]);
        $pricing->ask('jita', ['36', 35]);
        $pricing->refreshWaiting('jita');

        $this->assertSame([['market' => 'jita', 'types' => [35, 36], 'retry' => true, 'trigger' => 'subscription']], $pricing->refreshed);
    }

    public function test_types_stop_waiting_once_they_are_fetched(): void
    {
        $pricing = self::service();

        $pricing->ask('jita', [34]);
        $pricing->ask('jita', [35]);
        $pricing->refreshWaiting('jita');
        $pricing->refreshWaiting('jita');

        $this->assertCount(1, $pricing->refreshed);
        $this->assertArrayNotHasKey(self::WAITING . 'jita', ArrayCache::$items);
    }

    public function test_the_end_of_window_fetch_asks_nobody_when_nothing_waited(): void
    {
        $pricing = self::service();

        $pricing->ask('amarr', [34]);
        $pricing->ask('amarr', [35]);
        $pricing->refreshWaiting('jita');

        $this->assertSame([], $pricing->refreshed);
    }

    /** A plugin subscribing on every request must not queue a job each time. */
    public function test_more_asks_inside_the_window_queue_nothing_more(): void
    {
        $pricing = self::service();

        foreach ([34, 35, 36, 37, 38] as $typeId) {
            $pricing->ask('jita', [$typeId]);
        }

        $this->assertCount(2, $pricing->queued);
        $this->assertSame([35, 36, 37, 38], ArrayCache::$items[self::WAITING . 'jita']);
    }

    public function test_asking_again_for_types_already_waiting_takes_no_lock(): void
    {
        $pricing = self::service();

        $pricing->ask('jita', [34]);
        $pricing->ask('jita', [35, 36]);
        $locks = $pricing->locks;

        for ($request = 0; $request < 20; $request++) {
            $pricing->ask('jita', [36, 35]);
        }

        $this->assertSame($locks, $pricing->locks);
        $this->assertCount(2, $pricing->queued);
    }

    /** Nowhere to keep them, so they are fetched there and then: never dropped. */
    public function test_types_that_cannot_be_kept_waiting_are_fetched_at_once(): void
    {
        $pricing = self::service();
        $pricing->lockFails = true;

        $pricing->ask('jita', [34]);
        $pricing->ask('jita', [35]);

        $this->assertSame(['market' => 'jita', 'types' => [35], 'waiting' => false, 'delay' => 0], $pricing->queued[1]);
    }

    public function test_one_market_does_not_hold_up_another(): void
    {
        $pricing = self::service();

        $pricing->ask('jita', [34]);
        $pricing->ask('amarr', [34]);

        $this->assertSame(['jita', 'amarr'], array_column($pricing->queued, 'market'));
        $this->assertSame([false, false], array_column($pricing->queued, 'waiting'));
    }

    public function test_an_ask_after_the_window_is_fetched_at_once_again(): void
    {
        $pricing = self::service();

        $pricing->ask('jita', [34]);
        ArrayCache::forget(self::WINDOW . 'jita');
        $pricing->ask('jita', [35]);

        $this->assertSame([[34], [35]], array_column($pricing->queued, 'types'));
    }

    /**
     * The window can still be open when the end-of-window fetch of the one
     * before it runs. A subscribe after that fetch has taken the waiting
     * types needs a fetch of its own.
     */
    public function test_an_ask_after_the_end_of_window_fetch_started_queues_another(): void
    {
        $pricing = self::service();

        $pricing->ask('jita', [34]);
        $pricing->ask('jita', [35]);
        $pricing->refreshWaiting('jita');
        $pricing->ask('jita', [36]);

        $this->assertSame([false, true, true], array_column($pricing->queued, 'waiting'));
        $this->assertSame([36], ArrayCache::$items[self::WAITING . 'jita']);
    }
}
