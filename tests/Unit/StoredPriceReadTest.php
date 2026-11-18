<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\PricingService;
use PHPUnit\Framework\TestCase;

/**
 * Stored prices are read for every type at once, and come back in the same
 * shape as when each type was read on its own.
 */
class StoredPriceReadTest extends TestCase
{
    /** Tritanium with both sides at Jita, Pyerite with only a sell order. */
    private static function rows(): array
    {
        $row = fn (int $typeId, string $side, float $min, float $max) => (object) [
            'type_id' => (string) $typeId, 'price_type' => $side,
            'price_min' => $min, 'price_max' => $max, 'price_avg' => ($min + $max) / 2, 'price_median' => $min,
            'price_percentile' => $min, 'price_stddev' => 0.1, 'volume' => 1000, 'order_count' => 4,
            'strategy' => 'fuzzwork', 'updated_at' => '2026-11-15 12:00:00',
        ];

        return [$row(34, 'buy', 3.50, 3.92), $row(34, 'sell', 4.16, 9.00), $row(35, 'sell', 12.0, 15.0)];
    }

    private static function service(array $rows): PricingService
    {
        return new class ($rows) extends PricingService {
            public array $reads = [];

            public function __construct(private array $rows)
            {
            }

            public function read(array $typeIds, string $market, string $priceType): array
            {
                return $this->fetchPricesForTypes($typeIds, $market, $priceType);
            }

            public function one($typeId, string $market, string $priceType)
            {
                return $this->fetchPriceForType($typeId, $market, $priceType);
            }

            protected function storedPriceRows(array $typeIds, string $market, array $sides): iterable
            {
                $this->reads[] = ['types' => $typeIds, 'market' => $market, 'sides' => $sides];

                return array_filter($this->rows, fn ($row) => in_array((int) $row->type_id, $typeIds, true)
                    && in_array($row->price_type, $sides, true));
            }
        };
    }

    public function test_both_sides_come_back_for_every_type_asked_in_one_read(): void
    {
        $pricing = self::service(self::rows());

        $prices = $pricing->read([34, 35, 36], 'jita', 'both');

        $this->assertCount(1, $pricing->reads);
        $this->assertSame(['buy', 'sell'], $pricing->reads[0]['sides']);
        $this->assertSame([34, 35, 36], array_keys($prices));
        $this->assertSame(['buy', 'sell'], array_keys($prices[34]));
        $this->assertSame(3.92, $prices[34]['buy']['max']);
        $this->assertSame(4.16, $prices[34]['sell']['min']);
        $this->assertNull($prices[35]['buy']);
        $this->assertSame(['buy' => null, 'sell' => null], $prices[36]);
    }

    public function test_one_side_comes_back_as_its_figures_alone(): void
    {
        $pricing = self::service(self::rows());

        $prices = $pricing->read([34, 35], 'jita', 'buy');

        $this->assertSame(['buy'], $pricing->reads[0]['sides']);
        $this->assertSame(3.50, $prices[34]['min']);
        $this->assertNull($prices[35]);
    }

    /** The plugins' own words for a price type are no side, as before. */
    public function test_a_price_type_that_is_no_side_reads_nothing(): void
    {
        $pricing = self::service(self::rows());

        $this->assertSame([34 => null], $pricing->read([34], 'jita', 'avg'));
        $this->assertSame([], $pricing->reads);
    }

    public function test_a_type_asked_for_twice_or_as_text_is_read_once(): void
    {
        $pricing = self::service(self::rows());

        $prices = $pricing->read(['34', 34, 35], 'jita', 'sell');

        $this->assertSame([34, 35], $pricing->reads[0]['types']);
        $this->assertSame(4.16, $prices[34]['min']);
        $this->assertSame([34, 35], array_keys($prices));
    }

    public function test_one_type_reads_the_way_it_always_did(): void
    {
        $pricing = self::service(self::rows());

        $this->assertSame(12.0, $pricing->one(35, 'jita', 'sell')['min']);
        $this->assertSame(['buy' => null, 'sell' => null], $pricing->one(99, 'jita', 'both'));
        $this->assertNull($pricing->one(35, 'jita', 'buy'));
    }

    public function test_nothing_asked_reads_nothing(): void
    {
        $pricing = self::service(self::rows());

        $this->assertSame([], $pricing->read([], 'jita', 'both'));
        $this->assertSame([], $pricing->reads);
    }
}
