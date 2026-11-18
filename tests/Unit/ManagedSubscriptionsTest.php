<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Models\TypeSubscription;
use PHPUnit\Framework\TestCase;

/**
 * A plugin that keeps its own subscriptions in step with what its users
 * need is marked as managing them, so the Subscriptions page does not offer
 * to delete them by hand, and may publish under its own prefix.
 */
class ManagedSubscriptionsTest extends TestCase
{
    public function test_every_plugin_that_subscribes_types_manages_its_own(): void
    {
        foreach (['mining-manager', 'structure-manager', 'buyback-manager', 'market-manager'] as $plugin) {
            $info = TypeSubscription::PLUGIN_MANAGED[$plugin] ?? null;

            $this->assertNotNull($info, $plugin);
            $this->assertSame(['label', 'icon', 'color', 'description'], array_keys($info), $plugin);
        }
    }

    public function test_each_managing_plugin_has_a_prefix_to_publish_under(): void
    {
        $prefixes = config('manager-core.events.publisher_prefixes');

        foreach (array_keys(TypeSubscription::PLUGIN_MANAGED) as $plugin) {
            $this->assertNotEmpty($prefixes[$plugin] ?? [], $plugin);
        }

        $this->assertSame(['market.'], $prefixes['market-manager']);
    }
}
