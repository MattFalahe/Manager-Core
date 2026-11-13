<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Models\PricingPreference;
use ManagerCore\Services\PricingService;
use PHPUnit\Framework\TestCase;

class PluginPriceTest extends TestCase
{
    /**
     * Zeolites at Jita on 7 November, as Manager Core held it from Fuzzwork:
     * each side's lowest and highest order, median and percentile. The rest
     * is made up.
     */
    private const ZEOLITES = [
        'buy' => ['min' => 0.01, 'max' => 778.0, 'avg' => 742.6, 'median' => 761.0, 'percentile' => 778.0, 'stddev' => 96.1, 'volume' => 2481000, 'order_count' => 41, 'strategy' => 'fuzzwork', 'updated_at' => '2026-11-07 12:00:00'],
        'sell' => ['min' => 1492.0, 'max' => 57000.0, 'avg' => 2689.4, 'median' => 2350.0, 'percentile' => 1494.75, 'stddev' => 8820.3, 'volume' => 905000, 'order_count' => 63, 'strategy' => 'fuzzwork', 'updated_at' => '2026-11-07 12:00:00'],
    ];

    private static function service(): PricingService
    {
        return new class extends PricingService {
            public function figures(object $row): array
            {
                return $this->formatPriceStats($row);
            }

            public function standIn(?array $stats): ?array
            {
                return $this->priceOnly($stats);
            }
        };
    }

    /** A plugin reads a side's lowest order under min and its highest under max. */
    public function test_a_stored_side_reads_back_under_the_names_plugins_read(): void
    {
        $row = (object) [
            'price_min' => '0.01', 'price_max' => '778.00', 'price_avg' => '742.60', 'price_median' => '761.00',
            'price_percentile' => '778.00', 'price_stddev' => '96.10', 'volume' => 2481000, 'order_count' => 41,
            'strategy' => 'fuzzwork', 'updated_at' => '2026-11-07 12:00:00',
        ];

        $figures = self::service()->figures($row);

        $this->assertSame(['min', 'max', 'avg', 'median', 'percentile', 'stddev', 'volume', 'order_count', 'strategy', 'updated_at'], array_keys($figures));
        $this->assertSame(0.01, $figures['min']);
        $this->assertSame(778.0, $figures['max']);
        $this->assertSame(778.0, $figures['percentile']);
    }

    /**
     * A stand-in stands in for the price a plugin's provider would give, not
     * for an order book. Mining Manager 3.0.0 reads the lowest figure of each
     * side, which in a whole book is the cheapest bid.
     */
    public function test_a_stand_in_answers_with_the_lowest_sell_and_the_highest_buy_in_every_figure(): void
    {
        $answer = self::service()->standIn(self::ZEOLITES);

        foreach (['min', 'max', 'avg', 'median', 'percentile'] as $figure) {
            $this->assertSame(778.0, $answer['buy'][$figure], "buy $figure");
            $this->assertSame(1492.0, $answer['sell'][$figure], "sell $figure");
        }
        $this->assertSame(0.0, $answer['buy']['stddev']);
    }

    public function test_a_stand_in_keeps_what_it_says_about_the_side(): void
    {
        $answer = self::service()->standIn(self::ZEOLITES + ['stood_in' => 'fuzzwork']);

        $this->assertSame(2481000, $answer['buy']['volume']);
        $this->assertSame(63, $answer['sell']['order_count']);
        $this->assertSame('2026-11-07 12:00:00', $answer['sell']['updated_at']);
        $this->assertSame('fuzzwork', $answer['stood_in']);
    }

    public function test_a_side_a_stand_in_has_no_price_for_is_no_answer(): void
    {
        $answer = self::service()->standIn(['buy' => ['min' => 0.0, 'max' => 0.0], 'sell' => self::ZEOLITES['sell']]);
        $this->assertNull($answer['buy']);
        $this->assertSame(1492.0, $answer['sell']['max']);

        $this->assertNull(self::service()->standIn(['buy' => null, 'sell' => self::ZEOLITES['sell']])['buy']);
        $this->assertNull(self::service()->standIn(null));
    }

    /** Mining Manager registers its preference as average. */
    public function test_every_name_for_the_midpoint_reads_as_avg(): void
    {
        $this->assertSame('avg', PricingPreference::normalisePriceType('average'));
        $this->assertSame('avg', PricingPreference::normalisePriceType('split'));
        $this->assertSame('avg', PricingPreference::normalisePriceType(' Average '));
        $this->assertSame('avg', PricingPreference::normalisePriceType('avg'));
        $this->assertSame('sell', PricingPreference::normalisePriceType('sell'));
        $this->assertSame('buy', PricingPreference::normalisePriceType('BUY'));

        foreach (PricingPreference::PRICE_TYPE_ALIASES as $alias => $type) {
            $this->assertContains($type, PricingPreference::PRICE_TYPES, $alias);
        }
    }
}
