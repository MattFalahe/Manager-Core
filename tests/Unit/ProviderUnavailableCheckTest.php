<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Services\Watchdog\Checks\ProviderUnavailableCheck;
use ManagerCore\Tests\ArrayCache;
use PHPUnit\Framework\TestCase;

/**
 * When the Watchdog's price provider check is due. The Watchdog stores the
 * alert's dedup keys once it is delivered; deliver() does the same here.
 */
class ProviderUnavailableCheckTest extends TestCase
{
    private const REFUSED = ['state' => 'refused', 'error' => 'Janice refused the request with HTTP 401 (check the API key)', 'since' => '2026-11-10T14:00:00+00:00'];

    protected function setUp(): void
    {
        ArrayCache::$items = [];
    }

    private static function check(): ProviderUnavailableCheck
    {
        return new class extends ProviderUnavailableCheck {
            public function alert(array $unavailable, array $failing, ?array $janiceFailure, ?array $janiceBack = null): ?array
            {
                return $this->buildAlert($unavailable, $failing, ['fuzzwork', 'janice'], $janiceFailure, $janiceBack);
            }
        };
    }

    private static function deliver(?array $alert): void
    {
        foreach ($alert['dedup_keys'] ?? [] as $kv) {
            ArrayCache::$items[$kv['key']] = 'sent';
        }
    }

    /** It repeated every hour for as long as the key stayed refused. */
    public function test_a_refused_janice_key_is_reported_once(): void
    {
        $check = self::check();

        $first = $check->alert([], [], self::REFUSED);
        $this->assertNotNull($first);
        $this->assertStringContainsString('HTTP 401', $first['message']);
        self::deliver($first);

        $this->assertNull($check->alert([], [], self::REFUSED), 'an hour later, still refused: nothing');
    }

    public function test_janice_failing_again_later_is_a_new_report(): void
    {
        $check = self::check();
        self::deliver($check->alert([], [], self::REFUSED));

        $this->assertNotNull($check->alert([], [], ['since' => '2026-11-12T08:00:00+00:00'] + self::REFUSED));
    }

    public function test_the_rest_still_repeats_every_hour_and_lists_janice_with_it(): void
    {
        $check = self::check();
        self::deliver($check->alert([], [], self::REFUSED));

        $alert = $check->alert([], ['amarr (Fuzzwork answered HTTP 503)'], self::REFUSED);
        $this->assertNotNull($alert);
        $this->assertStringContainsString('amarr', $alert['message']);
        $this->assertStringContainsString('janice', $alert['message']);
        self::deliver($alert);

        $this->assertNull($check->alert([], ['amarr (Fuzzwork answered HTTP 503)'], self::REFUSED), 'inside the hour');

        unset(ArrayCache::$items['mc:watchdog:dedup:provider_unavailable']);
        $this->assertNotNull($check->alert([], ['amarr (Fuzzwork answered HTTP 503)'], self::REFUSED), 'the hour is over');
    }

    public function test_the_end_of_a_reported_failure_is_reported_once_in_green(): void
    {
        $check = self::check();
        self::deliver($check->alert([], [], self::REFUSED));

        $back = ['from' => self::REFUSED['since'], 'at' => '2026-11-11T09:10:00+00:00'];
        $alert = $check->alert([], [], null, $back);
        $this->assertSame('resolved', $alert['severity']);
        $this->assertStringContainsString('answering again (failing since 2026-11-10 14:00 UTC, answering since 2026-11-11 09:10 UTC)', $alert['message']);
        self::deliver($alert);

        $this->assertNull($check->alert([], [], null, $back));
    }

    public function test_the_end_of_a_failure_nobody_was_told_about_says_nothing(): void
    {
        $this->assertNull(self::check()->alert([], [], null, ['from' => self::REFUSED['since'], 'at' => '2026-11-11T09:10:00+00:00']));
    }

    public function test_nothing_wrong_is_no_alert(): void
    {
        $this->assertNull(self::check()->alert([], [], null));
    }
}
