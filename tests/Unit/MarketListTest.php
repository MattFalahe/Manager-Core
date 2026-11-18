<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Http\Controllers\MarketsController;
use ManagerCore\Services\PricingService;
use PHPUnit\Framework\TestCase;

/**
 * pricing.listMarkets tells a plugin which markets it can offer and whether
 * each one's provider prices the market's own place, so no plugin has to
 * read Manager Core's tables or providers to find out.
 */
class MarketListTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // The controller only needs a parent to load here.
        if (!class_exists('Seat\Web\Http\Controllers\Controller')) {
            eval('namespace Seat\Web\Http\Controllers; class Controller {}');
        }
    }

    private static function service(array $markets = []): PricingService
    {
        return new class ($markets) extends PricingService {
            public array $asked = [];

            public function __construct(private array $markets)
            {
            }

            protected function effectiveMarkets(bool $enabledOnly): array
            {
                $this->asked[] = $enabledOnly;

                return array_filter($this->markets, fn ($row) => !$enabledOnly || ($row['is_enabled'] ?? true));
            }

            /** No Janice key, and no SeAT price provider installed. */
            protected function providerAvailable(string $provider): bool
            {
                return in_array($provider, ['esi', 'fuzzwork', 'goonpraisal'], true);
            }
        };
    }

    private static function markets(): array
    {
        return [
            'jita' => ['name' => 'Jita', 'market_type' => 'hub', 'provider' => 'fuzzwork', 'provider_slug' => null, 'region_id' => 10000002,
                'is_enabled' => true, 'last_refresh_at' => new \DateTimeImmutable('2026-11-16 12:00:05', new \DateTimeZone('UTC')), 'last_refresh_status' => 'ok'],
            'hek' => ['name' => 'Hek', 'market_type' => 'hub', 'provider' => 'janice', 'provider_slug' => null,
                'is_enabled' => false, 'last_refresh_at' => null, 'last_refresh_status' => null],
            'amarr-fuzzwork' => ['name' => 'Amarr (Fuzzwork)', 'market_type' => 'hub', 'provider' => 'fuzzwork', 'provider_slug' => 'amarr',
                'is_enabled' => true, 'last_refresh_at' => '2026-11-16 08:00:00', 'last_refresh_status' => 'provider_down'],
            'c-j6mt-1st-taj-mahgoon' => ['name' => 'C-J6MT', 'market_type' => 'citadel', 'provider' => 'esi', 'provider_slug' => '1049588174021',
                'is_enabled' => true, 'last_refresh_at' => null, 'last_refresh_status' => null],
        ];
    }

    public function test_each_market_comes_with_what_a_plugin_shows(): void
    {
        $jita = self::service(self::markets())->listMarkets(true)['jita'];
        unset($jita['providers']);

        $this->assertSame([
            'key' => 'jita',
            'name' => 'Jita',
            'market_type' => 'hub',
            'provider' => 'fuzzwork',
            'provider_label' => 'Fuzzwork',
            'is_enabled' => true,
            'last_refresh_at' => '2026-11-16T12:00:05+00:00',
            'last_refresh_status' => 'ok',
            'prices_own_place' => true,
            'place_reason' => null,
        ], $jita);
    }

    /** What a plugin may name for its prices at each market, and why not where it can't. */
    public function test_each_market_says_what_every_provider_could_price_there(): void
    {
        $markets = self::service(self::markets())->listMarkets();

        $jita = $markets['jita']['providers'];
        $this->assertSame(['esi', 'janice', 'fuzzwork', 'goonpraisal', 'seat'], array_keys($jita));
        $this->assertSame(['label' => 'Fuzzwork', 'own' => true, 'available' => true, 'prices_place' => true, 'place_reason' => null], $jita['fuzzwork']);
        $this->assertSame(['label' => 'Janice', 'own' => false, 'available' => false, 'prices_place' => true, 'place_reason' => null], $jita['janice']);
        $this->assertTrue($jita['esi']['prices_place']);
        $this->assertTrue($jita['goonpraisal']['prices_place']);
        $this->assertFalse($jita['seat']['prices_place']);

        // A structure is read through ESI only for a market of its own, and
        // Fuzzwork or Janice would price it at Jita.
        $structure = $markets['c-j6mt-1st-taj-mahgoon']['providers'];
        $this->assertTrue($structure['esi']['own']);
        $this->assertTrue($structure['esi']['prices_place']);
        $this->assertStringContainsString('Jita 4-4', $structure['fuzzwork']['place_reason']);
        $this->assertStringContainsString('Jita 4-4', $structure['janice']['place_reason']);

        // The slug names Amarr for Fuzzwork, the market's own provider; it
        // means nothing to another provider, which goes by the key.
        $this->assertStringContainsString('Jita 4-4', $markets['amarr-fuzzwork']['providers']['janice']['place_reason']);
        $this->assertStringContainsString('region', $markets['amarr-fuzzwork']['providers']['esi']['place_reason']);
    }

    public function test_only_enabled_markets_unless_all_are_asked_for(): void
    {
        $pricing = self::service(self::markets());

        $this->assertSame(['jita', 'amarr-fuzzwork', 'c-j6mt-1st-taj-mahgoon'], array_keys($pricing->listMarkets()));

        $all = $pricing->listMarkets(false);
        $this->assertSame(['jita', 'hek', 'amarr-fuzzwork', 'c-j6mt-1st-taj-mahgoon'], array_keys($all));
        $this->assertFalse($all['hek']['is_enabled']);
        $this->assertSame([true, false], $pricing->asked);
    }

    /** The 13 November case: Amarr through Fuzzwork under another key, priced at Jita. */
    public function test_a_market_its_provider_prices_somewhere_else_says_so(): void
    {
        $market = self::service(self::markets())->listMarkets()['amarr-fuzzwork'];

        $this->assertFalse($market['prices_own_place']);
        $this->assertStringContainsString('Jita 4-4', $market['place_reason']);
        $this->assertSame('provider_down', $market['last_refresh_status']);
    }

    public function test_a_structure_read_through_esi_is_priced_at_its_own_place(): void
    {
        $market = self::service(self::markets())->listMarkets()['c-j6mt-1st-taj-mahgoon'];

        $this->assertTrue($market['prices_own_place']);
        $this->assertSame('MCPraisal', $market['provider_label']);
    }

    public function test_janice_takes_its_place_from_the_slug_then_the_key(): void
    {
        $pricing = self::service();

        $this->assertNull($pricing->placeReason('janice', 'dodixie-janice', 'Dodixie'));
        $this->assertNull($pricing->placeReason('janice', 'rens'));
        $this->assertStringContainsString('Jita 4-4', $pricing->placeReason('janice', 'my-market', 'C-J6MT'));
    }

    public function test_goonpraisal_prices_a_market_it_has_a_name_for(): void
    {
        $pricing = self::service();

        $this->assertNull($pricing->placeReason('goonpraisal', 'insmother-c-j6mt', 'C-J6MT'));
        $this->assertNull($pricing->placeReason('goonpraisal', 'jita'));
        $this->assertNotNull($pricing->placeReason('goonpraisal', 'my-market'));
    }

    public function test_a_seat_price_provider_never_prices_a_place(): void
    {
        $this->assertNotNull(self::service()->placeReason('seat', 'jita'));
    }

    /** The Markets form refuses exactly the markets this calls priced elsewhere. */
    public function test_it_agrees_with_what_the_markets_form_refuses(): void
    {
        $pricing = self::service();

        $cases = [
            ['fuzzwork', 'jita', null], ['fuzzwork', 'amarr-fuzzwork', 'amarr'], ['fuzzwork', 'c-j6mt', null],
            ['janice', 'hek', null], ['janice', 'hek-janice', 'hek'], ['janice', 'my-market', 'the-forge'], ['janice', 'my-market', null],
            ['esi', 'my-market', null], ['goonpraisal', 'jita', null],
        ];

        foreach ($cases as [$provider, $key, $slug]) {
            $refused = MarketsController::placeError(['provider' => $provider, 'provider_slug' => $slug], $key) !== null;

            $this->assertSame($refused, $pricing->placeReason($provider, $key, $slug) !== null, "$provider $key " . var_export($slug, true));
        }
    }

    public function test_a_price_names_the_provider_that_wrote_it(): void
    {
        $this->assertSame('esi', PricingService::providerOfStrategy('orders'));
        $this->assertSame('fuzzwork', PricingService::providerOfStrategy('Fuzzwork '));
        $this->assertSame('seat', PricingService::providerOfStrategy('seat-price-provider'));
        $this->assertNull(PricingService::providerOfStrategy('fallback'));
        $this->assertNull(PricingService::providerOfStrategy(null));
    }

    /** Every strategy a provider writes has to be one a plugin can read back as a provider. */
    public function test_every_strategy_a_provider_writes_is_known(): void
    {
        $written = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Services/{PriceProviders,ESI}/*.php', GLOB_BRACE) as $file) {
            preg_match_all("/'strategy' => (?:\(string\) \(\\\$row->strategy \?\? )?'([a-z-]+)'/", file_get_contents($file), $found);
            $written = array_merge($written, $found[1]);
        }

        $this->assertNotEmpty($written);
        foreach (array_unique($written) as $strategy) {
            $this->assertNotNull(PricingService::providerOfStrategy($strategy), $strategy);
        }
    }
}
