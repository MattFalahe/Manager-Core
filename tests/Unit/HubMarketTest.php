<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\PriceProviders\FuzzworkPriceProvider;
use ManagerCore\Services\PriceProviders\JanicePriceProvider;
use PHPUnit\Framework\TestCase;

class HubMarketTest extends TestCase
{
    /** Janice's market id for an MC market, without the key the constructor reads. */
    private static function janice(string $market): string
    {
        $janice = new class extends JanicePriceProvider {
            public function __construct()
            {
            }

            public function param(string $market): string
            {
                return $this->marketParam($market);
            }
        };

        return $janice->param($market);
    }

    /** Janice's market 1 is The Forge, Jita's whole region, and Janice never answered it. */
    public function test_janice_is_asked_for_its_own_amarr_market(): void
    {
        $this->assertSame('115', self::janice('amarr'));
        $this->assertSame('2', self::janice('jita'));
        $this->assertTrue(JanicePriceProvider::tracksMarket('Amarr'));
    }

    public function test_janice_prices_a_market_it_has_no_id_for_at_jita(): void
    {
        $this->assertSame('2', self::janice('dodixie'));
        $this->assertFalse(JanicePriceProvider::tracksMarket('dodixie'));
    }

    /**
     * Asked about a region, Fuzzwork answers with every order in it: on 8
     * November The Forge's cheapest Tritanium sat outside Jita, under what
     * Jita 4-4 sold it for.
     */
    public function test_fuzzwork_is_asked_about_each_hub_at_its_station(): void
    {
        $this->assertSame(60003760, FuzzworkPriceProvider::stationFor('jita'));
        $this->assertSame(60008494, FuzzworkPriceProvider::stationFor('amarr'));
        $this->assertSame(60011866, FuzzworkPriceProvider::stationFor('dodixie'));
        $this->assertSame(60005686, FuzzworkPriceProvider::stationFor('hek'));
        $this->assertSame(60004588, FuzzworkPriceProvider::stationFor('rens'));
        $this->assertSame(60003760, FuzzworkPriceProvider::stationFor('Jita'));
        $this->assertTrue(FuzzworkPriceProvider::tracksMarket('rens'));
    }

    public function test_fuzzwork_prices_a_market_it_cant_see_at_jita_4_4(): void
    {
        $this->assertSame(60003760, FuzzworkPriceProvider::stationFor('insmother-c-j6mt'));
        $this->assertFalse(FuzzworkPriceProvider::tracksMarket('insmother-c-j6mt'));
    }
}
