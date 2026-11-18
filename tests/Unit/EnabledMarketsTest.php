<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Models\Market;
use PHPUnit\Framework\TestCase;

/**
 * The five hubs come from the config and from the table. The table's row
 * decides whether a hub is enabled, so Disable on the Markets page takes a
 * hub off the list the scheduled refresh goes through.
 */
class EnabledMarketsTest extends TestCase
{
    protected function setUp(): void
    {
        MarketRows::$tableMissing = false;
        MarketRows::$rows = [
            self::row('jita', 'fuzzwork', true),
            self::row('amarr', 'janice', false),
            self::row('dodixie', 'fuzzwork', true),
            self::row('hek', 'fuzzwork', false),
            self::row('rens', 'goonpraisal', true),
            self::row('c-j6mt-1st-taj-mahgoon', 'esi', true, 'citadel'),
            self::row('old-structure', 'esi', false, 'citadel'),
        ];
    }

    private static function row(string $key, string $provider, bool $enabled, string $type = 'hub'): object
    {
        return (object) [
            'key' => $key, 'name' => ucfirst($key), 'market_type' => $type, 'provider' => $provider,
            'provider_slug' => null, 'region_id' => 10000002, 'system_ids' => [], 'system_name' => null,
            'system_id' => null, 'structure_name' => null, 'is_custom' => $type === 'citadel',
            'is_enabled' => $enabled, 'last_refresh_at' => null, 'last_refresh_status' => null,
        ];
    }

    public function test_a_disabled_hub_is_not_on_the_enabled_list(): void
    {
        $enabled = MarketRows::getEffectiveMarkets(true);

        $this->assertSame(['jita', 'dodixie', 'rens', 'c-j6mt-1st-taj-mahgoon'], array_keys($enabled));
    }

    public function test_an_enabled_hub_keeps_the_provider_on_its_row(): void
    {
        $enabled = MarketRows::getEffectiveMarkets(true);

        $this->assertSame('goonpraisal', $enabled['rens']['provider']);
        $this->assertSame('fuzzwork', $enabled['jita']['provider']);
    }

    public function test_every_market_is_there_when_all_are_asked_for(): void
    {
        $all = MarketRows::getEffectiveMarkets(false);

        $this->assertSame(
            ['jita', 'amarr', 'dodixie', 'hek', 'rens', 'c-j6mt-1st-taj-mahgoon', 'old-structure'],
            array_keys($all)
        );
        $this->assertSame('janice', $all['amarr']['provider']);
    }

    /** A disabled market can still be looked up by its key. */
    public function test_a_disabled_hub_is_still_found_by_key(): void
    {
        $this->assertSame('janice', MarketRows::getMarketConfig('amarr')['provider']);
    }

    /** Before the migrations have run there are no rows to go by. */
    public function test_the_config_hubs_stand_while_the_table_is_missing(): void
    {
        MarketRows::$tableMissing = true;

        $enabled = MarketRows::getEffectiveMarkets(true);

        $this->assertSame(['jita', 'amarr', 'dodixie', 'hek', 'rens'], array_keys($enabled));
        $this->assertSame('fuzzwork', $enabled['amarr']['provider']);
    }

    public function test_a_hub_with_no_row_stands_on_its_config_default(): void
    {
        MarketRows::$rows = [self::row('jita', 'fuzzwork', false)];

        $this->assertSame(['amarr', 'dodixie', 'hek', 'rens'], array_keys(MarketRows::getEffectiveMarkets(true)));
    }
}

/** The markets table as a test sets it. */
class MarketRows extends Market
{
    public static array $rows = [];
    public static bool $tableMissing = false;

    public static function query()
    {
        if (self::$tableMissing) {
            throw new \Exception("Table 'manager_core_markets' doesn't exist");
        }

        return new class (self::$rows) {
            public function __construct(private array $rows)
            {
            }

            public function get(): array
            {
                return $this->rows;
            }
        };
    }
}
