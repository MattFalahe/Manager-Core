<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Topics;
use PHPUnit\Framework\TestCase;

/**
 * Mining Manager announces a bill that was paid or let off and is open
 * again. Until the topic is registered, Topics::publish drops it as unknown.
 */
class MiningTaxReopenedTopicTest extends TestCase
{
    /** What Mining Manager sends: its usual bill block plus the reopen. */
    private const PAYLOAD = [
        'tax_id' => 812,
        'character_id' => 2117834560,
        'period' => '2026-11-01',
        'period_type' => 'monthly',
        'period_start' => '2026-11-01',
        'period_end' => '2026-11-30',
        'amount_owed' => 41250000.0,
        'amount_paid' => 0.0,
        'outstanding' => 41250000.0,
        'status' => 'unpaid',
        'due_date' => '2026-12-07',
        'corporation_id' => null,
        'role_id' => null,
        'idempotency_key' => 'tax_reopened:812:1795480000',
        'previous_status' => 'paid',
        'reopened_at' => '2026-11-24T10:15:00+00:00',
    ];

    public function test_the_topic_is_registered_as_mining_managers(): void
    {
        $topic = Topics::describe('mining.tax_reopened');

        $this->assertNotNull($topic);
        $this->assertSame('mining-manager', $topic['publisher']);
        $this->assertSame(['tax_id', 'character_id', 'status', 'previous_status'], $topic['required']);
    }

    public function test_what_mining_manager_sends_carries_every_required_field(): void
    {
        $missing = array_diff(Topics::describe('mining.tax_reopened')['required'], array_keys(self::PAYLOAD));

        $this->assertSame([], array_values($missing));
    }

    public function test_the_template_keys_one_per_reopen_for_a_publisher_without_its_own(): void
    {
        $compose = new \ReflectionMethod(Topics::class, 'composeKey');
        $compose->setAccessible(true);

        $template = Topics::describe('mining.tax_reopened')['idempotency_template'];
        $first = $compose->invoke(null, $template, self::PAYLOAD);
        $second = $compose->invoke(null, $template, ['reopened_at' => '2026-11-25T08:00:00+00:00'] + self::PAYLOAD);

        $this->assertSame('tax_reopened:812:2026-11-24T10:15:00+00:00', $first);
        $this->assertNotSame($first, $second);
    }
}
