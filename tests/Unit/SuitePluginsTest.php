<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\PluginBridge;
use PHPUnit\Framework\TestCase;

/**
 * Which of the suite's plugins an install lists: on the Plugin Bridge page,
 * in Diagnostics, in Help and in the version check.
 */
class SuitePluginsTest extends TestCase
{
    /** @param string[] $installed provider classes that load */
    private static function listed(array $installed): array
    {
        $bridge = new class extends PluginBridge {
            public static array $installed = [];

            public function __construct()
            {
            }

            protected static function classLoads(string $class): bool
            {
                return in_array($class, self::$installed, true);
            }
        };
        $bridge::$installed = $installed;

        return array_keys($bridge::suitePlugins());
    }

    public function test_market_manager_is_listed_only_where_it_is_installed(): void
    {
        $this->assertNotContains('market-manager', self::listed([]));
        $this->assertNotContains('market-manager', self::listed(['MiningManager\MiningManagerServiceProvider']));

        $listed = self::listed(['MarketManager\MarketManagerServiceProvider']);
        $this->assertContains('market-manager', $listed);
        $this->assertSame('market-manager', end($listed));
    }

    /** Every other plugin shows as Offline where it isn't installed, so it is listed everywhere. */
    public function test_every_other_plugin_is_listed_whether_it_is_installed_or_not(): void
    {
        $everyOther = array_values(array_diff(array_keys(config('manager-core.bridge.compatible_plugins')), ['market-manager']));

        $this->assertCount(7, $everyOther);
        $this->assertSame($everyOther, self::listed([]));
    }
}
