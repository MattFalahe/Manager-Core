<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\ESI\MarketDataService;
use ManagerCore\Tests\ArrayCache;
use PHPUnit\Framework\TestCase;

class StructureReadTest extends TestCase
{
    /** Tritanium and Pyerite were asked for and priced. The book also held Mexallon, which nobody asked for. */
    private const READ = ['types' => [34, 35], 'priced_types' => [34, 35], 'book_types' => [34, 35, 36]];

    protected function setUp(): void
    {
        ArrayCache::$items = [];
    }

    private static function service(): MarketDataService
    {
        return new class extends MarketDataService {
            public function __construct()
            {
            }

            public function covers(array $typeIds): bool
            {
                return $this->structureReadCovers('keepstar', $typeIds);
            }

            public function keep(array $read): void
            {
                ArrayCache::$items[$this->structureReadKey('keepstar')] = $read;
            }
        };
    }

    private static function readSecondsAgo(int $seconds, array $read): MarketDataService
    {
        $service = self::service();
        $service->keep(['at' => time() - $seconds] + $read);

        return $service;
    }

    public function test_a_fresh_read_covers_what_it_was_asked_for(): void
    {
        $this->assertTrue(self::readSecondsAgo(60, self::READ)->covers([34, 35]));
    }

    /** A read sees the whole book, so an item with no orders in it was answered too. */
    public function test_an_item_the_structure_has_no_orders_for_counts_as_read(): void
    {
        $this->assertTrue(self::readSecondsAgo(60, self::READ)->covers([34, 11399]));
    }

    public function test_an_item_with_orders_nobody_asked_the_read_for_needs_another_read(): void
    {
        $this->assertFalse(self::readSecondsAgo(60, self::READ)->covers([34, 36]));
    }

    public function test_a_read_older_than_fifteen_minutes_covers_nothing(): void
    {
        $this->assertTrue(self::readSecondsAgo(14 * 60, self::READ)->covers([34]));
        $this->assertFalse(self::readSecondsAgo(16 * 60, self::READ)->covers([34]));
    }

    /** A read kept before the book was listed only knows the items it was asked for. */
    public function test_a_read_from_before_the_book_was_listed_covers_only_what_it_was_asked_for(): void
    {
        $service = self::readSecondsAgo(60, ['types' => [34, 35]]);

        $this->assertTrue($service->covers([34, 35]));
        $this->assertFalse($service->covers([34, 11399]));
    }

    public function test_no_read_covers_nothing(): void
    {
        $this->assertFalse(self::service()->covers([34]));
    }

    /** Prices a keepstar the way an appraisal or the scheduled refresh does, recording what it would have read or queued. */
    private static function pricer(bool $inRequest, ?array $read, bool $lockBusy = false): MarketDataService
    {
        $service = new class ($inRequest, $lockBusy) extends MarketDataService {
            public array $reads = [];
            public array $queued = [];
            public array $waits = [];

            public function __construct(private bool $inRequest, private bool $lockBusy)
            {
            }

            public function price(array $typeIds): void
            {
                $this->updateStructureMarketPrices($typeIds, 'keepstar', ['provider_slug' => '1035466617946']);
            }

            public function keep(array $read): void
            {
                ArrayCache::$items[$this->structureReadKey('keepstar')] = $read;
            }

            protected function inWebRequest(): bool
            {
                return $this->inRequest;
            }

            protected function withStructureReadLock(string $market, int $wait, callable $read): void
            {
                $this->waits[] = $wait;
                if ($this->lockBusy) {
                    throw new \Illuminate\Contracts\Cache\LockTimeoutException();
                }
                $read();
            }

            protected function readStructureMarket(string $market, int $structureId, array $requested): void
            {
                $this->reads[] = $requested;
            }

            protected function queueStructureRead(string $market, array $typeIds): void
            {
                // The real one also holds the queue for STRUCTURE_FRESH_SECONDS; this records what reached it.
                if (\Illuminate\Support\Facades\Cache::add('mc.structure_market.' . $market . '.queued', 1, 900)) {
                    $this->queued[] = $typeIds;
                }
            }
        };
        if ($read !== null) {
            $service->keep($read);
        }

        return $service;
    }

    /** An appraisal on a structure read three hours ago used to read every page of it inside the page. */
    public function test_in_a_page_an_old_read_that_saw_every_item_answers_and_a_new_read_is_queued_once(): void
    {
        $service = self::pricer(true, ['at' => time() - 3 * 3600] + self::READ);
        $service->price([34, 11399]);
        $this->assertSame([34], $service->lastFreshTypeIds(), 'what the read priced counts as answered');

        $service->price([35]);
        $this->assertSame([], $service->reads, 'nothing is read inside the page');
        $this->assertSame([], $service->waits, 'and the read lock is never touched');
        $this->assertSame([[34, 11399]], $service->queued, 'one read is queued for a run of appraisals');
    }

    public function test_in_a_page_an_item_no_read_has_seen_is_read_without_waiting_for_another_read(): void
    {
        $service = self::pricer(true, ['at' => time() - 3 * 3600] + self::READ);
        $service->price([34, 36]);

        $this->assertSame([[34, 36]], $service->reads);
        $this->assertSame([0], $service->waits);
        $this->assertSame([], $service->queued);
    }

    /** It waited up to two minutes for the other read before. */
    public function test_in_a_page_a_read_already_running_leaves_the_cached_prices_at_once(): void
    {
        $service = self::pricer(true, null, true);
        $service->price([34]);

        $this->assertSame([0], $service->waits);
        $this->assertSame([], $service->reads);
        $this->assertSame([34], $service->lastFetchFailures()['type_ids']);
        $this->assertStringContainsString('is running', $service->lastFetchFailures()['error']);
    }

    public function test_the_scheduled_refresh_reads_an_old_structure_again_and_waits_for_a_running_read(): void
    {
        $service = self::pricer(false, ['at' => time() - 3 * 3600] + self::READ);
        $service->price([34]);

        $this->assertSame([[34]], $service->reads);
        $this->assertSame([120], $service->waits);
        $this->assertSame([], $service->queued);
    }

    public function test_a_fresh_read_answers_in_a_page_and_in_the_refresh_alike(): void
    {
        foreach ([true, false] as $inRequest) {
            ArrayCache::$items = [];
            $service = self::pricer($inRequest, ['at' => time() - 60] + self::READ);
            $service->price([34, 35]);

            $this->assertSame([], $service->reads);
            $this->assertSame([], $service->queued);
            $this->assertSame([34, 35], $service->lastFreshTypeIds());
        }
    }
}
