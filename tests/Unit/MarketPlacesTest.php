<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\MarketPlaces;
use ManagerCore\Services\PricingService;
use PHPUnit\Framework\TestCase;

/**
 * The Markets form finds a place by its name or ID and offers only the
 * providers that can price it there, so nobody has to know an ID by heart
 * or learn which provider covers what from a refused save.
 *
 * SeAT's tables are stood in for by rows as the dev server has them.
 */
class MarketPlacesTest extends TestCase
{
    private const STRUCTURES = [
        ['structure_id' => 1049588174021, 'structure_name' => 'C-J6MT - 1st Taj Mahgoon', 'type_id' => 35834, 'type_name' => 'Keepstar'],
        ['structure_id' => 1050253515055, 'structure_name' => 'C-J6MT - McManfist Backupstar', 'type_id' => 35834, 'type_name' => 'Keepstar'],
        ['structure_id' => 1049995488072, 'structure_name' => 'C-J6MT - T2 Repro - T2 React', 'type_id' => 35836, 'type_name' => 'Tatara'],
    ];

    private const SYSTEMS = [
        ['system_id' => 30002187, 'system_name' => 'Amarr', 'security' => 1.0, 'region_id' => 10000043, 'region_name' => 'Domain'],
        ['system_id' => 30000772, 'system_name' => 'C-J6MT', 'security' => -0.288745160757246, 'region_id' => 10000009, 'region_name' => 'Insmother'],
        ['system_id' => 30000839, 'system_name' => 'GB-6X5', 'security' => -0.524653857436131, 'region_id' => 10000009, 'region_name' => 'Insmother'],
        ['system_id' => 30000142, 'system_name' => 'Jita', 'security' => 0.945913116665, 'region_id' => 10000002, 'region_name' => 'The Forge'],
        ['system_id' => 30000144, 'system_name' => 'Perimeter', 'security' => 0.953123230586721, 'region_id' => 10000002, 'region_name' => 'The Forge'],
        ['system_id' => 30004807, 'system_name' => 'UALX-3', 'security' => -0.186761902519926, 'region_id' => 10000061, 'region_name' => 'Tenerifis'],
    ];

    private const REGIONS = [
        ['region_id' => 10000043, 'region_name' => 'Domain'],
        ['region_id' => 10000009, 'region_name' => 'Insmother'],
        ['region_id' => 10000016, 'region_name' => 'Lonetrek'],
        ['region_id' => 10000061, 'region_name' => 'Tenerifis'],
        ['region_id' => 10000002, 'region_name' => 'The Forge'],
    ];

    /** The dev server's markets on 17 November, as Market::toArray() gives them. */
    private static function devMarkets(): array
    {
        $hub = fn (int $id, string $key, string $name, int $region, int $system) => [
            'id' => $id, 'key' => $key, 'name' => $name, 'market_type' => 'hub', 'provider' => 'fuzzwork', 'provider_slug' => null,
            'region_id' => $region, 'system_id' => null, 'system_ids' => [$system], 'is_custom' => false, 'is_enabled' => true,
        ];
        $goon = fn (int $id, string $key, string $name, string $code, int $region, array $systems, bool $enabled) => [
            'id' => $id, 'key' => $key, 'name' => $name, 'market_type' => 'citadel', 'provider' => 'goonpraisal', 'provider_slug' => $code,
            'region_id' => $region, 'system_id' => null, 'system_ids' => $systems, 'is_custom' => false, 'is_enabled' => $enabled,
        ];

        return [
            $hub(1, 'jita', 'Jita (The Forge)', 10000002, 30000142),
            $hub(2, 'amarr', 'Amarr (Domain)', 10000043, 30002187),
            $hub(3, 'dodixie', 'Dodixie (Sinq Laison)', 10000032, 30002659),
            $hub(4, 'hek', 'Hek (Metropolis)', 10000042, 30002053),
            $hub(5, 'rens', 'Rens (Heimatar)', 10000030, 30002510),
            $goon(6, 'insmother-c-j6mt', 'Insmother (C-J6MT)', 'C-J6MT', 10000009, [30000772], true),
            $goon(7, 'insmother-lawn', 'Insmother LAWN (GB-6X5)', 'GB-6X5', 10000009, [], false),
            $goon(8, 'tenerifis-ualx3', 'Tenerifis (UALX-3)', 'UALX-3', 10000016, [], false),
            [
                'id' => 13, 'key' => 'c-j6mt-c-j6mt-1st-taj-mahgoon', 'name' => 'C-J6MT 1st Taj Mahgoon (ESI)', 'market_type' => 'citadel',
                'provider' => 'esi', 'provider_slug' => '1049588174021', 'region_id' => 10000009, 'system_id' => 30000772,
                'system_ids' => [30000772], 'is_custom' => true, 'is_enabled' => true,
            ],
        ];
    }

    /** No Janice key unless asked for, and no SeAT price provider installed. */
    private static function places(?array $markets = null, ?int $editing = null, array $unavailable = ['janice', 'seat']): MarketPlaces
    {
        $pricing = new class extends PricingService {
            public function __construct()
            {
            }
        };

        return new class ($pricing, $markets ?? self::devMarkets(), $editing, $unavailable) extends MarketPlaces {
            public function __construct(PricingService $pricing, array $markets, ?int $editing, private array $unavailable)
            {
                parent::__construct($pricing, $markets, $editing);
            }

            protected function providerAvailable(string $provider): bool
            {
                return !in_array($provider, $this->unavailable, true);
            }

            protected function structureRows(string $query): array
            {
                $id = ctype_digit($query) ? (int) $query : null;

                return array_values(array_filter(array_map([$this, 'structureRow'], array_column(MarketPlacesTest::structures(), 'structure_id')),
                    fn ($row) => $row['structure_id'] === $id
                        || (in_array($row['type_id'], self::MARKET_STRUCTURE_TYPES, true) && stripos($row['structure_name'] . ' ' . $row['system_name'], $query) !== false)));
            }

            protected function structureRow(int $structureId): ?array
            {
                foreach (MarketPlacesTest::structures() as $structure) {
                    if ($structure['structure_id'] === $structureId) {
                        return $structure + $this->systemRow(30000772);
                    }
                }

                return null;
            }

            protected function systemsNamed(array $names): array
            {
                $byName = [];
                foreach (MarketPlacesTest::systems() as $system) {
                    if (in_array($system['system_name'], $names, true)) {
                        $byName[strtoupper($system['system_name'])] = $system;
                    }
                }

                return $byName;
            }

            protected function systemRows(string $query): array
            {
                return array_values(array_filter(MarketPlacesTest::systems(), fn ($system) => stripos($system['system_name'], $query) !== false));
            }

            protected function systemRow(int $systemId): ?array
            {
                return array_values(array_filter(MarketPlacesTest::systems(), fn ($system) => $system['system_id'] === $systemId))[0] ?? null;
            }

            protected function regionRows(string $query): array
            {
                return array_values(array_filter(MarketPlacesTest::regions(), fn ($region) => stripos($region['region_name'], $query) !== false));
            }

            protected function regionRow(int $regionId): ?array
            {
                return array_values(array_filter(MarketPlacesTest::regions(), fn ($region) => $region['region_id'] === $regionId))[0] ?? null;
            }
        };
    }

    public static function structures(): array
    {
        return self::STRUCTURES;
    }

    public static function systems(): array
    {
        return self::SYSTEMS;
    }

    public static function regions(): array
    {
        return self::REGIONS;
    }

    private static function offered(array $place): array
    {
        return array_keys(array_filter($place['providers'], fn ($provider) => $provider['offered']));
    }

    public function test_a_structure_found_by_name_is_read_through_esi_by_its_id(): void
    {
        $results = self::places([])->search('citadel', 'taj');

        $this->assertCount(1, $results);
        $place = $results[0];

        $this->assertSame('structure', $place['kind']);
        $this->assertSame('C-J6MT - 1st Taj Mahgoon', $place['label']);
        $this->assertSame('Keepstar · C-J6MT (-0.3), Insmother · 1049588174021', $place['detail']);
        $this->assertSame('C-J6MT - 1st Taj Mahgoon', $place['name']);
        $this->assertSame('c-j6mt-1st-taj-mahgoon', $place['key']);
        $this->assertSame(10000009, $place['fields']['region_id']);
        $this->assertSame(30000772, $place['fields']['system_id']);
        $this->assertSame(['esi'], self::offered($place));
        $this->assertSame('1049588174021', $place['providers']['esi']['slug']);
        $this->assertSame('esi', $place['provider']);
        $this->assertFalse($place['needs_system']);
    }

    public function test_goonpraisal_is_pointed_at_its_system_market_instead_of_one_structure(): void
    {
        $place = self::places([])->search('citadel', 'taj')[0];

        $this->assertSame('Goonpraisal prices C-J6MT as a whole, not one structure. Pick its C-J6MT market from the list instead.',
            $place['providers']['goonpraisal']['reason']);
        $this->assertSame('Janice prices only the five trade hubs.', $place['providers']['janice']['reason']);
        $this->assertStringContainsString('Jita 4-4', $place['providers']['fuzzwork']['reason']);
    }

    public function test_a_structure_already_read_is_not_offered_twice(): void
    {
        $place = self::places()->search('citadel', 'taj')[0];

        $this->assertSame([], self::offered($place));
        $this->assertSame('Already on the list as C-J6MT 1st Taj Mahgoon (ESI).', $place['providers']['esi']['reason']);
        $this->assertNull($place['provider']);
        $this->assertSame(['c-j6mt-c-j6mt-1st-taj-mahgoon'], array_column($place['existing'], 'key'));
        $this->assertTrue($place['existing'][0]['custom']);
    }

    public function test_the_market_being_edited_never_counts_as_already_there(): void
    {
        $place = self::places(null, 13)->resolve(['market_type' => 'citadel', 'provider_slug' => '1049588174021']);

        $this->assertSame('structure', $place['kind']);
        $this->assertSame(['esi'], self::offered($place));
        $this->assertSame([], $place['existing']);
    }

    public function test_only_structures_that_can_hold_a_market_are_suggested_by_name(): void
    {
        $places = self::places([]);

        $this->assertSame([], $places->search('citadel', 'T2 Repro'));

        $byId = $places->search('citadel', '1049995488072');
        $this->assertCount(1, $byId);
        $this->assertSame('structure', $byId[0]['kind']);
        $this->assertSame('Tatara', $byId[0]['fields']['structure_type']);
    }

    public function test_a_structure_seat_has_not_seen_is_typed_by_id_and_placed_by_its_system(): void
    {
        $places = self::places([]);

        $typed = $places->search('citadel', '1060000000001');
        $this->assertCount(1, $typed);
        $this->assertSame('typed', $typed[0]['kind']);
        $this->assertSame('Structure 1060000000001', $typed[0]['label']);
        $this->assertTrue($typed[0]['needs_system']);
        $this->assertSame('1060000000001', $typed[0]['providers']['esi']['slug']);

        $placed = $places->resolve(['market_type' => 'citadel', 'structure_id' => '1060000000001', 'system_id' => '30000772']);
        $this->assertSame('typed', $placed['kind']);
        $this->assertFalse($placed['needs_system']);
        $this->assertSame('C-J6MT (-0.3), Insmother', $placed['detail']);
        $this->assertSame(10000009, $placed['fields']['region_id']);
        $this->assertSame('esi', $placed['provider']);
    }

    public function test_goonpraisal_citadel_markets_are_found_by_code_or_region(): void
    {
        $insmother = self::places([])->search('citadel', 'insmother');

        $this->assertSame(['C-J6MT', 'GB-6X5'], array_column($insmother, 'label'));
        $this->assertSame('goonpraisal', $insmother[0]['kind']);
        $this->assertSame('Insmother (C-J6MT)', $insmother[0]['name']);
        $this->assertSame('insmother-c-j6mt', $insmother[0]['key']);
        $this->assertSame(['goonpraisal'], self::offered($insmother[0]));
        $this->assertSame('C-J6MT', $insmother[0]['providers']['goonpraisal']['slug']);
        $this->assertStringStartsWith('MCPraisal reads one structure', $insmother[0]['providers']['esi']['reason']);

        $ualx = self::places([])->search('citadel', 'ualx');
        $this->assertSame(['UALX-3'], array_column($ualx, 'label'));
        $this->assertSame("Goonpraisal's citadel market · Tenerifis", $ualx[0]['detail']);
    }

    public function test_a_seeded_goonpraisal_market_is_already_on_the_list(): void
    {
        $place = self::places()->search('citadel', 'C-J6MT');
        $goon = array_values(array_filter($place, fn ($found) => $found['kind'] === 'goonpraisal'))[0];

        $this->assertSame('Already on the list as Insmother (C-J6MT).', $goon['providers']['goonpraisal']['reason']);
        $this->assertSame(['insmother-c-j6mt'], array_column($goon['existing'], 'key'));
        $this->assertSame('insmother-c-j6mt-2', $goon['key']);
    }

    public function test_a_trade_hub_offers_every_provider_that_prices_it_and_no_other(): void
    {
        $results = self::places()->search('hub', 'jita');

        $this->assertCount(1, $results);
        $jita = $results[0];

        $this->assertSame('system', $jita['kind']);
        $this->assertSame('Jita (The Forge)', $jita['name']);
        $this->assertSame('jita-2', $jita['key']);
        $this->assertSame('The Forge · security 0.9 · trade hub', $jita['detail']);
        $this->assertSame(['esi', 'goonpraisal', 'janice'], self::offered($jita));
        $this->assertNull($jita['providers']['esi']['slug']);
        $this->assertSame('jita', $jita['providers']['goonpraisal']['slug']);
        $this->assertSame('jita', $jita['providers']['janice']['slug']);
        $this->assertSame("Reads The Forge's public order book, Jita only. No token needed.", $jita['providers']['esi']['note']);
        $this->assertSame('Fuzzwork prices only the five trade hubs, and Jita is already on the list.', $jita['providers']['fuzzwork']['reason']);
        $this->assertSame(['jita'], array_column($jita['existing'], 'key'));
        $this->assertSame('esi', $jita['provider']);
    }

    public function test_janice_without_its_key_is_offered_but_says_what_it_needs(): void
    {
        $janice = self::places()->search('hub', 'jita')[0]['providers']['janice'];

        $this->assertTrue($janice['offered']);
        $this->assertFalse($janice['available']);
        $this->assertStringEndsWith("Set Janice's API key under Settings, Pricing first.", $janice['note']);

        $withKey = self::places(null, null, ['seat'])->search('hub', 'jita')[0]['providers']['janice'];
        $this->assertTrue($withKey['available']);
        $this->assertSame("Janice's Jita market, at the hub's station.", $withKey['note']);
    }

    public function test_a_system_that_is_no_trade_hub_is_read_through_esi_alone(): void
    {
        $perimeter = self::places()->search('hub', 'perimeter')[0];

        $this->assertSame('The Forge · security 1.0', $perimeter['detail']);
        $this->assertSame(['esi'], self::offered($perimeter));
        $this->assertSame('Goonpraisal prices only Jita, Amarr and Dodixie among the hubs.', $perimeter['providers']['goonpraisal']['reason']);
        $this->assertStringEndsWith('Pick Jita, Amarr, Dodixie, Hek or Rens as the system.', $perimeter['providers']['janice']['reason']);
        $this->assertStringContainsString('Jita 4-4', $perimeter['providers']['fuzzwork']['reason']);
        $this->assertSame([], $perimeter['existing']);
    }

    public function test_a_region_is_read_whole_through_esi(): void
    {
        $domain = self::places()->search('hub', 'domain');

        $this->assertCount(1, $domain);
        $this->assertSame('region', $domain[0]['kind']);
        $this->assertSame('Domain', $domain[0]['name']);
        $this->assertSame('domain', $domain[0]['key']);
        $this->assertSame(['esi'], self::offered($domain[0]));
        $this->assertSame("Reads Domain's public order book. No token needed.", $domain[0]['providers']['esi']['note']);

        // Amarr reads one system of Domain, so it isn't the same market.
        $this->assertSame([], $domain[0]['existing']);
    }

    public function test_a_search_needs_two_characters(): void
    {
        $this->assertSame([], self::places()->search('hub', 'j'));
        $this->assertSame([], self::places()->search('citadel', ' '));
    }

    public function test_a_taken_key_takes_the_next_number_and_still_fits(): void
    {
        $places = self::places([['key' => 'jita'], ['key' => 'jita-2'], ['key' => str_repeat('a', 32)]]);

        $this->assertSame('jita-3', $places->uniqueKey('Jita'));
        $this->assertSame('perimeter', $places->uniqueKey('Perimeter'));

        $long = $places->uniqueKey(str_repeat('a', 40));
        $this->assertSame(str_repeat('a', 30) . '-2', $long);
    }

    public function test_before_a_place_is_picked_the_providers_that_never_price_a_new_market_are_refused(): void
    {
        $unplaced = self::places()->unplaced();

        $this->assertSame(['esi', 'goonpraisal', 'janice'], array_keys(array_filter($unplaced, fn ($provider) => $provider['offered'])));
        $this->assertStringContainsString('Jita 4-4', $unplaced['fuzzwork']['reason']);
        $this->assertSame('Prices Jita, Amarr, Dodixie and its Imperium citadel markets.', $unplaced['goonpraisal']['note']);
        $this->assertStringEndsWith("Set Janice's API key under Settings, Pricing first.", $unplaced['janice']['note']);
        $this->assertFalse($unplaced['seat']['offered']);
    }

    public function test_goonpraisal_rows_are_placed_by_the_system_their_code_names(): void
    {
        // The seeded Tenerifis row carries Lonetrek's region ID.
        $names = self::places()->rowNames(self::devMarkets());

        $this->assertSame('UALX-3 (-0.2), Tenerifis', $names['codes']['UALX-3']);
        $this->assertSame('C-J6MT (-0.3), Insmother', $names['codes']['C-J6MT']);
    }

    public function test_security_reads_as_eve_shows_it(): void
    {
        $this->assertSame('0.9', MarketPlaces::security(0.945913116665));
        $this->assertSame('1.0', MarketPlaces::security(0.953123230586721));
        $this->assertSame('-0.3', MarketPlaces::security(-0.288745160757246));
        $this->assertSame('-0.1', MarketPlaces::security(-0.0604315059750488));
        $this->assertSame('0.1', MarketPlaces::security(0.03));
        $this->assertSame('0.0', MarketPlaces::security(0.0));
    }

    public function test_each_hub_row_shows_what_every_provider_can_do_there(): void
    {
        $markets = [];
        foreach (self::devMarkets() as $row) {
            $markets[$row['key']] = $row;
        }

        $pricing = new class ($markets) extends PricingService {
            public function __construct(private array $markets)
            {
            }

            protected function effectiveMarkets(bool $enabledOnly): array
            {
                return $this->markets;
            }

            protected function providerAvailable(string $provider): bool
            {
                return in_array($provider, ['esi', 'fuzzwork', 'goonpraisal'], true);
            }
        };

        $listed = $pricing->listMarkets(false);
        $chips = MarketPlaces::chips('Jita (The Forge)', $listed['jita']['providers'], ['esi' => ['Mining Manager', 'Mining Manager', 'Buyback Manager']]);

        $this->assertSame(['esi', 'goonpraisal', 'janice', 'fuzzwork', 'seat'], array_keys($chips));
        $this->assertSame(
            ['esi' => 'ok', 'goonpraisal' => 'ok', 'janice' => 'setup', 'fuzzwork' => 'own', 'seat' => 'no'],
            array_map(fn ($chip) => $chip['state'], $chips)
        );
        $this->assertSame(2, $chips['esi']['readers']);
        $this->assertStringEndsWith('Read through it by Mining Manager and Buyback Manager.', $chips['esi']['title']);
        $this->assertSame("Can price Jita (The Forge) once Janice's API key is set under Settings, Pricing.", $chips['janice']['title']);
    }

    public function test_a_market_its_own_provider_cannot_price_is_flagged(): void
    {
        $chips = MarketPlaces::chips('Amarr (Fuzzwork)', [
            'fuzzwork' => ['label' => 'Fuzzwork', 'own' => true, 'available' => true, 'prices_place' => false,
                'place_reason' => 'Fuzzwork prices only the five trade hubs, so it prices this market at Jita 4-4.'],
        ]);

        $this->assertSame('own-off', $chips['fuzzwork']['state']);
        $this->assertSame("Amarr (Fuzzwork)'s own provider. Fuzzwork prices only the five trade hubs, so it prices this market at Jita 4-4.", $chips['fuzzwork']['title']);
    }
}
