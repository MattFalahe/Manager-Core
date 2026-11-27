<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Topics;
use PHPUnit\Framework\TestCase;

/**
 * Structure Manager 2.0.5 publishes these topics and subscribes to three of
 * them. Its ecosystem checks fail for any the registry does not know.
 */
class StructureManagerTopicsTest extends TestCase
{
    private const TOPICS = [
        'structure.alert.shield_reinforced',
        'structure.alert.armor_reinforced',
        'structure.alert.hull_reinforced',
        'structure.alert.destroyed',
        'structure.alert.destroyed_confirmed',
        'structure.alert.anchoring_started',
        'structure.alert.sov_reinforced',
        'structure.alert.entosis_in_progress',
        'structure.alert.fuel_critical',
        'structure.alert.fuel_recovered',
        'structure.doctrine.changed',
        'structure_manager.timer.created',
        'structure_manager.timer.updated',
        'structure_manager.timer.dismissed',
        'structure_manager.timer.upcoming_24h',
        'structure_manager.timer.upcoming_6h',
        'structure_manager.timer.upcoming_1h',
        'structure_manager.timer.elapsed',
        'structure_manager.timer.recovered',
    ];

    private const TIMER_ACTIONS = ['created', 'updated', 'dismissed', 'upcoming_24h', 'upcoming_6h', 'upcoming_1h', 'elapsed', 'recovered'];

    /** The scaffold Structure Manager puts on every timer event. */
    private const TIMER_PAYLOAD = [
        'source_plugin' => 'structure-manager',
        'schema_version' => 1,
        'event_id' => 'sm-evt-6f1c2a9e-1d4b-4c8e-9a51-3b7d2e0f8a14',
        'lifecycle_action' => 'upcoming_24h',
        'timer_id' => 4417,
        'event_type' => 'reinforce_armor',
        'severity' => 'critical',
        'category_group' => 'tactical',
        'corporation_id' => 98000001,
        'role_id' => null,
        'structure_id' => 1035466617946,
        'structure_name' => 'C-J6MT - Keepstar',
        'structure_type' => 'Keepstar',
        'structure_type_id' => 35834,
        'system_id' => 30001198,
        'system_name' => 'C-J6MT',
        'system_security' => -0.4,
        'owner_corporation_name' => 'Example Corp',
        'attacker_corporation_name' => null,
        'eve_time' => '2026-11-28T19:30:00+00:00',
        'seconds_until' => 72000,
        'is_elapsed' => false,
        'notes' => null,
        'source_reference' => 'esi-notif:1980451123',
        'dismissed_at' => null,
        'is_manual' => false,
        'url' => '/structure-manager/command-board?timer_id=4417',
        'tags' => [],
    ];

    /** A destroyed structure zKillboard had no killmail for. */
    private const CONFIRMED_PAYLOAD = [
        'source_plugin' => 'structure-manager',
        'schema_version' => 1,
        'event_id' => 'sm-evt-0b8e4d3c-7a2f-4e61-b9d0-5c1a8f3e6d27',
        'event_type' => 'destroyed_confirmed',
        'structure_id' => 1035466617946,
        'corporation_id' => 98000001,
        'structure_type_id' => 35834,
        'system_id' => 30001198,
        'severity' => 'critical',
        'source_reference' => 'killmail-enrichment:1035466617946',
        'original_event_id' => 'sm-evt-2d7f9b1a-3c6e-4a08-8f52-e4b1c9d7a360',
        'enrichment_outcome' => 'not_found_in_zkb',
        'attacker_resolution_status' => 'unresolved',
    ];

    public function test_every_structure_manager_topic_is_registered_as_its_own(): void
    {
        foreach (self::TOPICS as $topic) {
            $entry = Topics::describe($topic);

            $this->assertNotNull($entry, "$topic is not registered");
            $this->assertSame('structure-manager', $entry['publisher'], $topic);
        }
    }

    public function test_what_structure_manager_sends_carries_every_required_field(): void
    {
        foreach (self::TIMER_ACTIONS as $action) {
            $required = Topics::describe("structure_manager.timer.$action")['required'];
            $this->assertSame([], array_values(array_diff($required, array_keys(self::TIMER_PAYLOAD))), $action);
        }

        $required = Topics::describe('structure.alert.destroyed_confirmed')['required'];
        $this->assertSame([], array_values(array_diff($required, array_keys(self::CONFIRMED_PAYLOAD))));
    }

    public function test_a_stage_is_keyed_once_per_timer_and_a_change_once_per_event(): void
    {
        $compose = new \ReflectionMethod(Topics::class, 'composeKey');
        $compose->setAccessible(true);
        $again = ['event_id' => 'sm-evt-9a3e5f71-2b8c-4d16-a0e4-7c5b1f9d2e83'] + self::TIMER_PAYLOAD;

        foreach (['upcoming_24h', 'upcoming_6h', 'upcoming_1h', 'elapsed'] as $stage) {
            $template = Topics::describe("structure_manager.timer.$stage")['idempotency_template'];
            $this->assertSame("structure_manager.timer.$stage:4417", $compose->invoke(null, $template, self::TIMER_PAYLOAD));
            $this->assertSame($compose->invoke(null, $template, self::TIMER_PAYLOAD), $compose->invoke(null, $template, $again), $stage);
        }

        foreach (['created', 'updated', 'dismissed', 'recovered'] as $change) {
            $template = Topics::describe("structure_manager.timer.$change")['idempotency_template'];
            $this->assertNotSame($compose->invoke(null, $template, self::TIMER_PAYLOAD), $compose->invoke(null, $template, $again), $change);
        }
    }
}
