<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\ESI\MarketDataService;
use PHPUnit\Framework\TestCase;

class OrderBookTest extends TestCase
{
    /**
     * Tritanium at Jita on 7 November ran from a 0.01 ISK bid to 4.05 on the
     * buy side, and from 4.09 to an order at 300,100,000 on the sell side.
     * The orders in between, and every volume, are made up.
     */
    private const BUY = [[4.00, 80000000], [0.01, 1000], [4.05, 400000000], [3.42, 50000000], [4.04, 250000000], [3.97, 120000000]];
    private const SELL = [[4.12, 90000000], [300100000.0, 1], [4.09, 300000000], [6.32, 2000000], [4.10, 150000000]];

    /** @return array<string, array> what reading these orders stores for each side */
    private static function stored(array $buy, array $sell): array
    {
        $orders = [];
        foreach ([[true, $buy], [false, $sell]] as [$isBuy, $book]) {
            foreach ($book as [$price, $volume]) {
                $orders[] = ['is_buy_order' => $isBuy, 'price' => $price, 'volume_remain' => $volume];
            }
        }

        $service = new class extends MarketDataService {
            public array $stored = [];

            public function __construct()
            {
            }

            public function read(array $orders): array
            {
                $this->calculateAndSavePrices(34, $orders, 'jita');

                return $this->stored;
            }

            protected function savePriceStats($typeId, $market, $priceType, array $stats)
            {
                $this->stored[$priceType] = $stats;
            }

            protected function updatePriceHistory($typeId, $market, array $buyOrders, array $sellOrders)
            {
            }
        };

        return $service->read($orders);
    }

    public function test_each_side_runs_from_its_lowest_order_to_its_highest(): void
    {
        $stored = self::stored(self::BUY, self::SELL);

        $this->assertSame(0.01, $stored['buy']['min']);
        $this->assertSame(4.05, $stored['buy']['max']);
        $this->assertSame(4.09, $stored['sell']['min']);
        $this->assertSame(300100000.0, $stored['sell']['max']);
    }

    /** The cheapest bid is the bottom of the buy side, nowhere near what the item sells for. */
    public function test_the_buy_percentile_sits_at_the_top_of_the_buy_orders(): void
    {
        $this->assertEqualsWithDelta(4.0495, self::stored(self::BUY, self::SELL)['buy']['percentile'], 0.00001);
    }

    public function test_the_sell_percentile_sits_at_the_bottom_of_the_sell_orders(): void
    {
        $this->assertEqualsWithDelta(4.0904, self::stored(self::BUY, self::SELL)['sell']['percentile'], 0.00001);
    }

    public function test_the_median_is_the_middle_order_or_halfway_between_the_middle_two(): void
    {
        $stored = self::stored(self::BUY, self::SELL);

        $this->assertEqualsWithDelta(3.985, $stored['buy']['median'], 0.00001);
        $this->assertSame(4.12, $stored['sell']['median']);
    }

    public function test_the_average_is_weighted_by_what_is_left_on_each_order(): void
    {
        $sell = self::stored([], [[4.00, 3], [8.00, 1]])['sell'];

        $this->assertSame(5.0, $sell['avg']);
        $this->assertSame(4, $sell['volume']);
        $this->assertSame(2, $sell['order_count']);
    }

    /** What was stored for a side stays until the side has orders again. */
    public function test_a_side_with_no_orders_is_left_as_it_was(): void
    {
        $this->assertSame(['sell'], array_keys(self::stored([], self::SELL)));
        $this->assertSame(['buy'], array_keys(self::stored(self::BUY, [])));
    }
}
