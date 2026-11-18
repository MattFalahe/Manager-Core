<?php

namespace ManagerCore\Tests\Unit;

use Carbon\Carbon;
use ManagerCore\Services\Watchdog\Checks\PriceCronOverdueCheck;
use PHPUnit\Framework\TestCase;

/**
 * The Watchdog's price cron check goes by when the scheduled refresh last
 * stored a price. A plugin asking for prices between the scheduled runs
 * keeps the newest price in the table young, and must not hide a schedule
 * that has stopped.
 */
class PriceCronOverdueCheckTest extends TestCase
{
    protected function setUp(): void
    {
        Carbon::setTestNow('2026-11-17 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    /**
     * @param string|null $scheduled when a scheduled refresh last stored a price, null if it never kept its time
     * @param string|null $newest    the newest price in the table
     */
    private static function check(?string $scheduled, ?string $newest, int $rows = 500): PriceCronOverdueCheck
    {
        return new class ($scheduled, $newest, $rows) extends PriceCronOverdueCheck {
            public function __construct(private ?string $scheduled, private ?string $newest, private int $rows)
            {
            }

            protected function priceRowCount(): int
            {
                return $this->rows;
            }

            protected function lastScheduledRefreshAt(): ?Carbon
            {
                return $this->scheduled ? Carbon::parse($this->scheduled) : null;
            }

            protected function newestPriceAt(): ?Carbon
            {
                return $this->newest ? Carbon::parse($this->newest) : null;
            }

            protected function resolveCronIntervalMinutes(): int
            {
                return 240;
            }
        };
    }

    /** The case the check went blind to: asks every quarter of an hour over a schedule dead since last night. */
    public function test_a_stopped_schedule_is_reported_while_a_plugin_keeps_prices_young(): void
    {
        $alert = self::check('2026-11-17 00:00:05', '2026-11-17 11:52:10')->run();

        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert['severity']);
        $this->assertStringContainsString('last stored a price 719min ago', $alert['message']);
        $this->assertStringContainsString('do not count', $alert['message']);
        $this->assertSame(719, $alert['context']['last_scheduled_refresh_age_min']);
        $this->assertArrayNotHasKey('newest_price_age_min', $alert['context']);
    }

    public function test_a_schedule_on_time_is_quiet_however_old_the_newest_price(): void
    {
        $this->assertNull(self::check('2026-11-17 08:00:05', '2026-11-17 08:00:05')->run());
        $this->assertNull(self::check('2026-11-17 04:00:05', '2026-11-17 04:00:05')->run(), 'one run missed is inside twice the interval');
    }

    public function test_a_day_without_a_scheduled_refresh_is_critical(): void
    {
        $alert = self::check('2026-11-16 08:00:00', '2026-11-17 11:52:10')->run();

        $this->assertSame('critical', $alert['severity']);
    }

    /** Between the update and the first scheduled run after it, nothing has kept its time yet. */
    public function test_before_the_schedule_has_kept_its_time_the_newest_price_is_what_counts(): void
    {
        $this->assertNull(self::check(null, '2026-11-17 08:00:05')->run());

        $alert = self::check(null, '2026-11-17 02:00:00')->run();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('Newest cached price is 600min old', $alert['message']);
        $this->assertSame(600, $alert['context']['newest_price_age_min']);
    }

    public function test_an_install_with_no_prices_yet_is_quiet(): void
    {
        $this->assertNull(self::check(null, null, 0)->run());
        $this->assertNull(self::check('2026-11-10 00:00:00', null, 0)->run());
    }
}
