<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Models\ESI\EsiNotificationDelivery;
use ManagerCore\Services\ESI\EsiNotificationRegistry;
use ManagerCore\Tests\ArrayLog;
use PHPUnit\Framework\TestCase;

/** Handlers as plugins register them, recording what they were handed. */
final class RecordingHandler
{
    public static array $handed = [];

    public static function handle($notification): void
    {
        self::$handed[] = $notification->notification_id;
    }
}

final class ThrowingHandler
{
    public static function handle($notification): void
    {
        throw new \RuntimeException('Discord answered 500');
    }
}

final class HandlerWithoutHandle
{
}

final class LongErrorHandler
{
    public static function handle($notification): void
    {
        throw new \RuntimeException(str_repeat('é', 400));
    }
}

class EsiNotificationRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingHandler::$handed = [];
        ArrayLog::$lines = [];
    }

    /** As Mining Manager, Structure Manager and HR Manager register today, the last two asking for nothing. */
    private static function registryAsOnDev(array $structureOptions = ['queued' => false]): EsiNotificationRegistry
    {
        $registry = new EsiNotificationRegistry();
        $registry->register(['MoonminingExtractionStarted'], RecordingHandler::class, 'mining-manager', ['queued' => false]);
        $registry->register(['StructureUnderAttack', 'StructureLostShields', 'MoonminingExtractionStarted'], ThrowingHandler::class, 'structure-manager', $structureOptions);
        $registry->register(['CorpAppAcceptMsg', 'CharLeftCorpMsg'], HandlerWithoutHandle::class, 'hr-manager');

        return $registry;
    }

    private static function ages(EsiNotificationRegistry $registry, string $type): array
    {
        return array_column($registry->getHandlerEntriesForType($type), 'max_age_hours', 'class');
    }

    public function test_each_type_gets_the_age_its_handler_asked_for_or_the_default_for_it(): void
    {
        $registry = self::registryAsOnDev(['queued' => false, 'max_age_hours' => ['StructureLostShields' => 48]]);

        $this->assertSame([RecordingHandler::class => 72.0, ThrowingHandler::class => 72.0], self::ages($registry, 'MoonminingExtractionStarted'));
        $this->assertSame([ThrowingHandler::class => 48.0], self::ages($registry, 'StructureLostShields'));
        $this->assertSame([ThrowingHandler::class => 2.0], self::ages($registry, 'StructureUnderAttack'));
        $this->assertSame([HandlerWithoutHandle::class => 2.0], self::ages($registry, 'CharLeftCorpMsg'));
        $this->assertSame(72.0, $registry->getLongestMaxAgeHours());
    }

    public function test_one_age_covers_every_type_registered_with_it(): void
    {
        $registry = self::registryAsOnDev(['queued' => false, 'max_age_hours' => 6]);

        $this->assertSame([ThrowingHandler::class => 6.0], self::ages($registry, 'StructureUnderAttack'));
        $this->assertSame([ThrowingHandler::class => 6.0], self::ages($registry, 'StructureLostShields'));
        $this->assertSame([RecordingHandler::class => 72.0, ThrowingHandler::class => 6.0], self::ages($registry, 'MoonminingExtractionStarted'));
    }

    public function test_the_longest_age_is_nothing_with_nobody_registered(): void
    {
        $this->assertSame(0.0, (new EsiNotificationRegistry())->getLongestMaxAgeHours());
    }

    /** A plugin's boot can run more than once in a process; the first registration stands. */
    public function test_registering_a_handler_again_keeps_the_age_it_first_got(): void
    {
        $registry = self::registryAsOnDev();
        $registry->register(['StructureUnderAttack'], ThrowingHandler::class, 'structure-manager', ['queued' => false, 'max_age_hours' => 300]);

        $this->assertSame([ThrowingHandler::class => 2.0], self::ages($registry, 'StructureUnderAttack'));
        $this->assertSame(72.0, $registry->getLongestMaxAgeHours());
    }

    public function test_the_key_pool_page_gets_each_plugins_ages(): void
    {
        $byPlugin = self::registryAsOnDev(['queued' => false, 'max_age_hours' => ['StructureLostShields' => 48, 'MoonminingExtractionStarted' => 24]])->getRegistrationsByPlugin();

        $this->assertSame(['MoonminingExtractionStarted' => 72.0], $byPlugin['mining-manager']['max_age_hours'], 'its own age, not the other plugin\'s for the same type');
        $this->assertSame(
            ['StructureUnderAttack' => 2.0, 'StructureLostShields' => 48.0, 'MoonminingExtractionStarted' => 24.0],
            $byPlugin['structure-manager']['max_age_hours']
        );
        $this->assertSame(['CorpAppAcceptMsg' => 2.0, 'CharLeftCorpMsg' => 2.0], $byPlugin['hr-manager']['max_age_hours']);
    }

    public function test_deliver_says_what_became_of_it_at_each_handler(): void
    {
        $registry = self::registryAsOnDev();
        $notification = (object) ['id' => 98, 'notification_id' => 2482556106, 'type' => 'MoonminingExtractionStarted'];
        [$mining, $structure] = $registry->getHandlerEntriesForType('MoonminingExtractionStarted');
        [$hr] = $registry->getHandlerEntriesForType('CharLeftCorpMsg');

        $this->assertSame([EsiNotificationDelivery::HANDLED, null], $registry->deliver($notification, $mining));
        $this->assertSame([2482556106], RecordingHandler::$handed);

        $this->assertSame([EsiNotificationDelivery::FAILED, 'Discord answered 500'], $registry->deliver($notification, $structure));
        $this->assertSame([EsiNotificationDelivery::FAILED, 'The handler has no handle() method.'], $registry->deliver($notification, $hr));
        $this->assertSame(
            [EsiNotificationDelivery::FAILED, 'The handler class does not exist.'],
            $registry->deliver($notification, ['class' => 'Gone\\Handler', 'queued' => false, 'queue' => null, 'plugin' => 'gone'])
        );
        $this->assertContains('error', array_column(ArrayLog::$lines, 0), 'a failing handler is logged');
    }

    public function test_a_long_error_is_cut_to_fit_its_column(): void
    {
        $registry = new EsiNotificationRegistry();
        $registry->register('StructureUnderAttack', LongErrorHandler::class, 'structure-manager', ['queued' => false]);

        [$status, $detail] = $registry->deliver((object) ['notification_id' => 1, 'type' => 'StructureUnderAttack'], $registry->getHandlerEntriesForType('StructureUnderAttack')[0]);

        $this->assertSame(EsiNotificationDelivery::FAILED, $status);
        $this->assertSame(255, mb_strlen($detail));
    }

    /** Plugins may call dispatch() themselves, and it still hands every handler the notification, however old. */
    public function test_dispatch_still_hands_it_to_every_handler_and_counts_those_that_took_it(): void
    {
        $registry = self::registryAsOnDev();
        $old = (object) ['id' => 98, 'notification_id' => 2482556106, 'type' => 'MoonminingExtractionStarted', 'timestamp' => '2026-10-01 00:00:00'];

        $this->assertSame(1, $registry->dispatch($old));
        $this->assertSame([2482556106], RecordingHandler::$handed);
        $this->assertSame(0, $registry->dispatch((object) ['notification_id' => 1, 'type' => 'SomethingNobodyWants']));
        $this->assertSame(0, $registry->dispatch((object) ['notification_id' => 1]));
    }
}
