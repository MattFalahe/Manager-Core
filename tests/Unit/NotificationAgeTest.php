<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\ESI\NotificationAge;
use PHPUnit\Framework\TestCase;

class NotificationAgeTest extends TestCase
{
    private const HOUR = 3600;

    /**
     * A moon extraction started at 05:48 on 7 November. ESI served it 652
     * minutes late, and SeAT stored its first copy at 16:40:35.
     */
    private const EXTRACTION_SENT = '2026-11-07 05:48:00';
    private const EXTRACTION_STORED = '2026-11-07 16:40:35';

    /** A character left the corporation at 12:07 the same day, served 273 minutes late. */
    private const LEFT_CORP_SENT = '2026-11-07 12:07:00';
    private const LEFT_CORP_STORED = '2026-11-07 16:40:49';

    private static function at(string $utc): int
    {
        return strtotime($utc . ' UTC');
    }

    public function test_a_handler_that_names_no_age_takes_two_hours_and_extraction_starts_take_three_days(): void
    {
        $this->assertSame(2.0, NotificationAge::hoursFor('StructureUnderAttack'));
        $this->assertSame(2.0, NotificationAge::hoursFor('CharLeftCorpMsg'));
        $this->assertSame(72.0, NotificationAge::hoursFor('MoonminingExtractionStarted'));
    }

    public function test_what_a_handler_asks_for_comes_first_one_age_for_all_or_one_per_type(): void
    {
        $this->assertSame(48.0, NotificationAge::hoursFor('StructureLostShields', 48));
        $this->assertSame(48.0, NotificationAge::hoursFor('StructureLostShields', ['StructureLostShields' => 48]));
        $this->assertSame(2.0, NotificationAge::hoursFor('StructureUnderAttack', ['StructureLostShields' => 48]));
        $this->assertSame(72.0, NotificationAge::hoursFor('MoonminingExtractionStarted', ['StructureLostShields' => 48]));
        $this->assertSame(1.0, NotificationAge::hoursFor('MoonminingExtractionStarted', 1), 'asking for less than the built-in age counts too');
        $this->assertSame(0.5, NotificationAge::hoursFor('StructureUnderAttack', 0.5));
        $this->assertSame(12.0, NotificationAge::hoursFor('StructureUnderAttack', '12'));
    }

    public function test_no_handler_takes_more_than_manager_core_keeps(): void
    {
        $this->assertSame(14 * 24, NotificationAge::INTAKE_HOURS);
        $this->assertSame(336.0, NotificationAge::hoursFor('StructureLostShields', 1000));
        $this->assertSame(336.0, NotificationAge::hoursFor('StructureLostShields', ['StructureLostShields' => INF]));
    }

    public function test_an_age_that_is_not_one_counts_as_not_asked(): void
    {
        foreach ([0, -5, 'soon', '', true, null, NAN, []] as $asked) {
            $this->assertSame(2.0, NotificationAge::hoursFor('StructureUnderAttack', $asked), var_export($asked, true));
            $this->assertSame(72.0, NotificationAge::hoursFor('MoonminingExtractionStarted', $asked), var_export($asked, true));
        }
    }

    /**
     * Nothing older than two hours was stored before the update, and the
     * first poll after it mustn't reach back two weeks for everything it
     * skipped.
     */
    public function test_the_intake_opens_out_from_the_update_instead_of_reaching_back(): void
    {
        $since = self::at('2026-11-08 14:00:00');

        $this->assertSame($since - 2 * self::HOUR, NotificationAge::intakeCutoff($since, $since));
        $this->assertSame($since - self::HOUR, NotificationAge::intakeCutoff($since + self::HOUR, $since), 'the old two hours still count just after it');
        $this->assertSame($since, NotificationAge::intakeCutoff($since + 3 * self::HOUR, $since));
        $this->assertSame($since, NotificationAge::intakeCutoff($since + 13 * 24 * self::HOUR, $since));
        $this->assertSame($since + 6 * 24 * self::HOUR, NotificationAge::intakeCutoff($since + 20 * 24 * self::HOUR, $since), 'and two weeks once it is that far back');
    }

    public function test_the_late_extraction_start_reaches_the_extraction_handler_and_nothing_with_two_hours(): void
    {
        $sent = self::at(self::EXTRACTION_SENT);
        $stored = self::at(self::EXTRACTION_STORED);
        $since = self::at('2026-11-01 00:00:00');
        $now = $stored + 60;

        $this->assertTrue(NotificationAge::isDue($sent, $stored, $now, $since, NotificationAge::hoursFor('MoonminingExtractionStarted')));
        $this->assertNull(NotificationAge::whyNotDue($sent, $stored, $now, $since, 72.0));

        $this->assertFalse(NotificationAge::isDue($sent, $stored, $now, $since, 2.0));
        $this->assertSame(
            'Reached Manager Core 10.9 h after CCP sent it, and the handler takes up to 2 h.',
            NotificationAge::whyNotDue($sent, $stored, $now, $since, 2.0)
        );
    }

    /** HR Manager's roster check finds leavers on its own; a late leave notice isn't sent. */
    public function test_the_late_leave_notice_is_too_old_for_a_handler_with_two_hours(): void
    {
        $sent = self::at(self::LEFT_CORP_SENT);
        $stored = self::at(self::LEFT_CORP_STORED);

        $this->assertFalse(NotificationAge::isDue($sent, $stored, $stored + 60, self::at('2026-11-01 00:00:00'), NotificationAge::hoursFor('CharLeftCorpMsg')));
        $this->assertTrue(NotificationAge::isDue($sent, $stored, $stored + 60, self::at('2026-11-01 00:00:00'), 6.0), 'a handler that asked for six hours gets it');
    }

    /** Never touch the past: anything sent before the update goes out as it would have before it. */
    public function test_a_notification_sent_before_the_update_goes_out_under_the_old_two_hours(): void
    {
        $sent = self::at(self::EXTRACTION_SENT);
        $stored = self::at(self::EXTRACTION_STORED);
        $since = self::at('2026-11-07 12:00:00');

        $this->assertFalse(NotificationAge::isDue($sent, $stored, $stored + 60, $since, 72.0));
        $this->assertSame(
            'Reached Manager Core 10.9 h after CCP sent it, and the handler takes up to 2 h. It was sent before Manager Core kept two weeks of notifications, so the 2 h default applied.',
            NotificationAge::whyNotDue($sent, $stored, $stored + 60, $since, 72.0)
        );

        // One that arrived on time still goes out, to every handler, within four hours.
        $this->assertTrue(NotificationAge::isDue($sent, $sent + 300, $sent + 4 * self::HOUR, $since, 72.0));
        $this->assertFalse(NotificationAge::isDue($sent, $sent + 300, $sent + 4 * self::HOUR + 1, $since, 72.0));
        $this->assertFalse(NotificationAge::isDue($sent, $sent + 300, $sent + 3 * self::HOUR, $since, 1.0), 'a handler asking for less keeps less');
    }

    public function test_the_age_is_inclusive_to_the_second(): void
    {
        $sent = self::at('2026-11-08 10:00:00');
        $since = $sent - self::HOUR;

        $this->assertTrue(NotificationAge::isDue($sent, $sent + 2 * self::HOUR, $sent + 2 * self::HOUR, $since, 2.0));
        $this->assertFalse(NotificationAge::isDue($sent, $sent + 2 * self::HOUR + 1, $sent + 2 * self::HOUR + 1, $since, 2.0));
        $this->assertTrue(NotificationAge::isDue($sent, $sent + 72 * self::HOUR, $sent + 72 * self::HOUR, $since, 72.0));
        $this->assertFalse(NotificationAge::isDue($sent, $sent + 72 * self::HOUR + 1, $sent + 72 * self::HOUR + 1, $since, 72.0));
        $this->assertTrue(NotificationAge::isDue($sent, $sent + 1800, $sent + 1800, $since, 0.5));
        $this->assertFalse(NotificationAge::isDue($sent, $sent + 1801, $sent + 1801, $since, 0.5));
    }

    /**
     * One that arrived in time still has as long again to go out, so a queue
     * that was busy for a while doesn't lose it, and one that stalled for
     * days doesn't send it days late.
     */
    public function test_one_that_arrived_in_time_goes_out_within_twice_the_age(): void
    {
        $sent = self::at('2026-11-08 10:00:00');
        $since = $sent - self::HOUR;
        $stored = $sent + 90 * 60;

        $this->assertTrue(NotificationAge::isDue($sent, $stored, $sent + 4 * self::HOUR, $since, 2.0));
        $this->assertFalse(NotificationAge::isDue($sent, $stored, $sent + 4 * self::HOUR + 1, $since, 2.0));
        $this->assertSame(
            'Not handed out by 4 h after CCP sent it, and the handler takes it within 4 h.',
            NotificationAge::whyNotDue($sent, $stored, $sent + 4 * self::HOUR + 1, $since, 2.0)
        );
        $this->assertTrue(NotificationAge::isDue($sent, $stored, $sent + 144 * self::HOUR, $since, 72.0));
        $this->assertFalse(NotificationAge::isDue($sent, $stored, $sent + 144 * self::HOUR + 1, $since, 72.0));
    }

    public function test_one_sent_the_second_the_archive_started_counts_as_after_it(): void
    {
        $since = self::at('2026-11-08 14:00:00');

        $this->assertTrue(NotificationAge::isDue($since, $since + 10 * self::HOUR, $since + 10 * self::HOUR + 60, $since, 72.0));
        $this->assertFalse(NotificationAge::isDue($since - 1, $since + 10 * self::HOUR, $since + 10 * self::HOUR + 60, $since, 72.0));
    }

    /** It arrived just in time; what held it back was waiting too long to go out. */
    public function test_the_reason_names_the_wait_when_it_arrived_exactly_on_its_age(): void
    {
        $sent = self::at('2026-11-08 10:00:00');

        $this->assertSame(
            'Not handed out by 4 h after CCP sent it, and the handler takes it within 4 h.',
            NotificationAge::whyNotDue($sent, $sent + 2 * self::HOUR, $sent + 4 * self::HOUR + 1, $sent - self::HOUR, 2.0)
        );
    }

    /** A clock that runs a little behind CCP's mustn't hold a notification back. */
    public function test_a_notification_stored_before_its_own_timestamp_is_on_time(): void
    {
        $sent = self::at('2026-11-08 10:00:00');

        $this->assertTrue(NotificationAge::isDue($sent, $sent - 30, $sent + 60, $sent - self::HOUR, 2.0));
    }

    /**
     * Dispatch reads notifications sent from its cutoff on. Anything a handler
     * is due has to be inside it, or it would never go out.
     */
    public function test_nothing_any_handler_is_due_falls_outside_what_dispatch_reads(): void
    {
        mt_srand(20261108);
        $now = self::at('2026-11-20 12:00:00');

        for ($run = 0; $run < 4000; $run++) {
            $since = $now - mt_rand(0, 30 * 24 * self::HOUR);
            $ages = array_map(fn () => [0.5, 1.0, 2.0, 6.0, 24.0, 48.0, 72.0, 336.0][mt_rand(0, 7)], range(1, mt_rand(1, 4)));
            $sent = $now - mt_rand(0, 40 * 24 * self::HOUR);
            $stored = $sent + mt_rand(0, $now - $sent);

            $cutoff = NotificationAge::dispatchCutoff($now, $since, max($ages));
            foreach ($ages as $hours) {
                if (NotificationAge::isDue($sent, $stored, $now, $since, $hours)) {
                    $this->assertGreaterThanOrEqual($cutoff, $sent, "sent $sent, stored $stored, since $since, $hours h");
                }
            }
        }
    }

    /** Right after the update dispatch reads the four hours it read before, and no further back. */
    public function test_dispatch_reads_no_further_back_than_before_until_the_update_is_older(): void
    {
        $since = self::at('2026-11-08 14:00:00');

        $this->assertSame($since - 4 * self::HOUR, NotificationAge::dispatchCutoff($since, $since, 72.0));
        $this->assertSame($since, NotificationAge::dispatchCutoff($since + 10 * self::HOUR, $since, 72.0));
        $this->assertSame($since + 6 * self::HOUR, NotificationAge::dispatchCutoff($since + 150 * self::HOUR, $since, 72.0));
        $this->assertSame($since + 10 * self::HOUR - 4 * self::HOUR, NotificationAge::dispatchCutoff($since + 10 * self::HOUR, $since, 1.0));
    }

    /** The reason is kept in a string(255) column. */
    public function test_every_reason_fits_its_column(): void
    {
        $sent = self::at('2026-11-08 10:00:00');

        foreach ([[$sent + 99999 * self::HOUR, $sent + 99999 * self::HOUR], [$sent, $sent + 99999 * self::HOUR]] as [$stored, $now]) {
            foreach ([0.5, 2.0, 336.0] as $hours) {
                foreach ([$sent - self::HOUR, $sent + self::HOUR] as $since) {
                    $why = NotificationAge::whyNotDue($sent, $stored, $now, $since, $hours);
                    $this->assertNotNull($why);
                    $this->assertLessThanOrEqual(200, mb_strlen($why), $why);
                }
            }
        }
    }
}
