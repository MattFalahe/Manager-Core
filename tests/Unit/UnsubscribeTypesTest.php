<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\PricingService;
use ManagerCore\Tests\ArrayLog;
use PHPUnit\Framework\TestCase;

/**
 * A plugin can drop every type it subscribes, one market's, or only the
 * types it names. Naming none drops none.
 */
class UnsubscribeTypesTest extends TestCase
{
    protected function setUp(): void
    {
        ArrayLog::$lines = [];
    }

    private static function service(): PricingService
    {
        return new class extends PricingService {
            /** @var array<int, array{plugin: string, market: ?string, types: ?array}> */
            public array $deleted = [];

            protected function deleteSubscriptions(string $pluginName, ?string $market, ?array $typeIds): int
            {
                $this->deleted[] = ['plugin' => $pluginName, 'market' => $market, 'types' => $typeIds];

                return $typeIds === null ? 476 : count($typeIds);
            }
        };
    }

    /** What every plugin calling with two arguments gets, as before. */
    public function test_without_types_a_whole_market_goes(): void
    {
        $pricing = self::service();

        $this->assertSame(476, $pricing->unregisterTypes('mining-manager', 'jita'));
        $this->assertSame([['plugin' => 'mining-manager', 'market' => 'jita', 'types' => null]], $pricing->deleted);
    }

    public function test_without_a_market_or_types_everything_goes(): void
    {
        $pricing = self::service();

        $pricing->unregisterTypes('mining-manager');

        $this->assertSame([['plugin' => 'mining-manager', 'market' => null, 'types' => null]], $pricing->deleted);
    }

    public function test_named_types_go_and_no_others(): void
    {
        $pricing = self::service();

        $this->assertSame(2, $pricing->unregisterTypes('market-manager', 'jita', [34, '35', 34]));
        $this->assertSame([['plugin' => 'market-manager', 'market' => 'jita', 'types' => [34, 35]]], $pricing->deleted);
    }

    /** A plugin that worked out nothing to drop must not lose the market. */
    public function test_an_empty_list_removes_nothing(): void
    {
        $pricing = self::service();

        $this->assertSame(0, $pricing->unregisterTypes('market-manager', 'jita', []));
        $this->assertSame([], $pricing->deleted);
    }

    public function test_named_types_can_go_from_every_market(): void
    {
        $pricing = self::service();

        $pricing->unregisterTypes('market-manager', null, [34]);

        $this->assertSame([['plugin' => 'market-manager', 'market' => null, 'types' => [34]]], $pricing->deleted);
    }
}
