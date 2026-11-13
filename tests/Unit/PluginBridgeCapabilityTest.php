<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\PluginBridge;
use ManagerCore\Tests\ArrayCache;
use ManagerCore\Tests\ArrayLog;
use PHPUnit\Framework\TestCase;

/**
 * Registering capabilities and storing their names on the plugins' registry
 * rows. The registry is an array here, and the test says when the boot ends.
 */
class PluginBridgeCapabilityTest extends TestCase
{
    protected function setUp(): void
    {
        ArrayCache::$items = [];
        ArrayLog::$lines = [];
    }

    /** A bridge over an array registry that counts its lookups and writes. */
    private static function bridge(array $rows = []): PluginBridge
    {
        return new class ($rows) extends PluginBridge {
            public array $rows = [];
            public array $lookups = [];
            public array $saves = [];
            public array $selfWrites = [];
            public array $bootCallbacks = [];
            public bool $booted = false;
            public bool $failLookups = false;

            public function __construct(array $rows)
            {
                foreach ($rows as $name => $capabilities) {
                    $this->rows[$name] = (object) ['plugin_name' => $name, 'capabilities' => $capabilities];
                }
            }

            public function finishBoot(): void
            {
                $this->booted = true;
                foreach ($this->bootCallbacks as $callback) {
                    $callback();
                }
                $this->bootCallbacks = [];
            }

            public function pending(): array
            {
                return $this->pendingCapabilities;
            }

            public function flushFor(string $pluginName): bool
            {
                return $this->flushPendingCapabilities($pluginName);
            }

            protected function whenBooted(callable $callback): void
            {
                if ($this->booted) {
                    $callback();

                    return;
                }
                $this->bootCallbacks[] = $callback;
            }

            protected function registryRows(array $pluginNames): array
            {
                $this->lookups[] = $pluginNames;
                if ($this->failLookups) {
                    throw new \RuntimeException('Base table or view not found');
                }

                return array_intersect_key($this->rows, array_flip($pluginNames));
            }

            protected function saveCapabilities($row, array $capabilities): void
            {
                $this->saves[] = [$row->plugin_name, $capabilities];
                $row->capabilities = $capabilities;
            }

            protected function saveSelfRegistration(string $pluginKey, array $values): void
            {
                $this->selfWrites[] = $pluginKey;
                $this->rows[$pluginKey] ??= (object) ['plugin_name' => $pluginKey, 'capabilities' => null];
            }
        };
    }

    private static function handler(): \Closure
    {
        return fn () => null;
    }

    /** Each plugin registers its capabilities in every process; that used to be a lookup and a log line apiece. */
    public function test_names_are_stored_once_the_boot_is_over_with_one_lookup_for_every_plugin(): void
    {
        $bridge = self::bridge(['corp-wallet-manager' => ['ratting.getCharacterIncome', 'ratting.getCharacterMonthly'], 'hr-manager' => ['hr.getAssessment']]);
        $bridge->registerCapability('corp-wallet-manager', 'ratting.getCharacterIncome', self::handler());
        $bridge->registerCapability('corp-wallet-manager', 'ratting.getCharacterMonthly', self::handler());
        $bridge->registerCapability('hr-manager', 'hr.getAssessment', self::handler());

        $this->assertSame([], $bridge->lookups, 'nothing is looked up while the app boots');
        $this->assertTrue($bridge->hasCapability('hr-manager', 'hr.getAssessment'), 'a capability can be called at once');

        $bridge->finishBoot();

        $this->assertSame([['corp-wallet-manager', 'hr-manager']], $bridge->lookups);
        $this->assertSame([], $bridge->saves, 'a list that has not changed is not written');
        $this->assertSame([], ArrayLog::$lines);
        $this->assertSame([], $bridge->pending());
    }

    public function test_a_new_capability_is_written_with_one_line_naming_the_plugin(): void
    {
        $bridge = self::bridge(['mining-manager' => ['mining.getTaxSummary']]);
        $bridge->registerCapability('mining-manager', 'mining.getTaxSummary', self::handler());
        $bridge->registerCapability('mining-manager', 'mining.getMoonValue', self::handler());
        $bridge->finishBoot();

        $this->assertSame([['mining-manager', ['mining.getTaxSummary', 'mining.getMoonValue']]], $bridge->saves);
        $this->assertCount(1, ArrayLog::$lines);
        $this->assertSame('info', ArrayLog::$lines[0][0]);
        $this->assertStringContainsString('mining-manager', ArrayLog::$lines[0][1]);
    }

    /** Manager Core has no registry row, so looking for one was a lookup that failed on every start. */
    public function test_manager_cores_own_capabilities_are_never_looked_up_but_are_listed(): void
    {
        $bridge = self::bridge();
        foreach (['pricing.getPrice', 'sde.typeName', 'bridge.discoverCapabilities'] as $capability) {
            $bridge->registerCapability(PluginBridge::SELF_KEY, $capability, self::handler());
        }
        $bridge->finishBoot();

        $this->assertSame([], $bridge->lookups);
        $this->assertSame([], $bridge->pending());
        $this->assertSame([], ArrayLog::$lines);
        $this->assertTrue($bridge->hasCapability('ManagerCore', 'sde.typeName'));
        $this->assertSame(['ManagerCore' => ['pricing.getPrice', 'sde.typeName', 'bridge.discoverCapabilities']], $bridge->registeredCapabilityNames('ManagerCore'));
    }

    public function test_the_names_registered_in_this_process_are_listed_for_one_plugin_or_all(): void
    {
        $bridge = self::bridge(['hr-manager' => []]);
        $bridge->registerCapability('ManagerCore', 'sde.typeName', self::handler());
        $bridge->registerCapability('hr-manager', 'hr.getAssessment', self::handler());
        $bridge->registerCapability('hr-manager', 'hr.getApplicationStatus', self::handler());

        $this->assertSame(['ManagerCore' => ['sde.typeName'], 'hr-manager' => ['hr.getAssessment', 'hr.getApplicationStatus']], $bridge->registeredCapabilityNames());
        $this->assertSame(['hr-manager' => ['hr.getAssessment', 'hr.getApplicationStatus']], $bridge->registeredCapabilityNames('hr-manager'));
        $this->assertSame([], $bridge->registeredCapabilityNames('structure-manager'));
    }

    public function test_a_plugin_without_a_row_keeps_its_names_until_discover_makes_one(): void
    {
        $bridge = self::bridge();
        $bridge->registerCapability('new-plugin', 'thing.do', self::handler());
        $bridge->finishBoot();

        $this->assertSame([['new-plugin']], $bridge->lookups);
        $this->assertSame(['new-plugin' => ['thing.do']], $bridge->pending());
        $this->assertSame([], ArrayLog::$lines);

        // discover() makes the row, then stores what was waiting for it.
        $bridge->rows['new-plugin'] = (object) ['plugin_name' => 'new-plugin', 'capabilities' => null];
        $this->assertTrue($bridge->flushFor('new-plugin'));
        $this->assertSame([['new-plugin', ['thing.do']]], $bridge->saves);
        $this->assertSame([], $bridge->pending());
    }

    public function test_a_capability_registered_after_the_boot_is_stored_at_once(): void
    {
        $bridge = self::bridge(['structure-manager' => []]);
        $bridge->finishBoot();
        $bridge->registerCapability('structure-manager', 'fuel.estimate', self::handler());

        $this->assertSame([['structure-manager']], $bridge->lookups);
        $this->assertSame([['structure-manager', ['fuel.estimate']]], $bridge->saves);
    }

    public function test_a_registry_that_cannot_be_read_keeps_the_names_for_later(): void
    {
        $bridge = self::bridge(['hr-manager' => []]);
        $bridge->failLookups = true;
        $bridge->registerCapability('hr-manager', 'hr.getAssessment', self::handler());
        $bridge->finishBoot();

        $this->assertSame(['hr-manager' => ['hr.getAssessment']], $bridge->pending());
        $this->assertSame('warning', ArrayLog::$lines[0][0] ?? null);
        $this->assertTrue($bridge->hasCapability('hr-manager', 'hr.getAssessment'));
    }

    /** SeAT Broadcast registers itself as it boots, which wrote its row on every page load. */
    public function test_a_plugin_that_registers_itself_writes_its_row_at_most_once_a_minute(): void
    {
        $bridge = self::bridge();
        $info = ['name' => 'SeAT Broadcast', 'class' => ArrayLog::class, 'package' => 'mattfalahe/seat-discord-pings', 'icon' => 'fa-bullhorn'];

        $bridge->registerSelf('seat-discord-pings', $info);
        $bridge->registerSelf('seat-discord-pings', $info);
        $this->assertSame(['seat-discord-pings'], $bridge->selfWrites, 'a second start within the minute writes nothing');
        $this->assertTrue($bridge->hasPlugin('seat-discord-pings'), 'the plugin is still known in memory');

        $bridge->registerSelf('seat-discord-pings', ['icon' => 'fa-bell'] + $info);
        $this->assertCount(2, $bridge->selfWrites, 'a change is written at once');

        ArrayCache::$items['mc_bridge_self_seat-discord-pings']['at'] -= 61;
        $bridge->registerSelf('seat-discord-pings', ['icon' => 'fa-bell'] + $info);
        $this->assertCount(3, $bridge->selfWrites, 'a minute on, the row is written again');
    }
}
