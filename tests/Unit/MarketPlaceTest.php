<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Http\Controllers\MarketsController;
use PHPUnit\Framework\TestCase;

/**
 * Fuzzwork and Janice price anything they don't know at Jita 4-4 and say
 * nothing about it, so the Markets form refuses a market they would price
 * that way.
 */
class MarketPlaceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // The controller only needs a parent to load here.
        if (!class_exists('Seat\Web\Http\Controllers\Controller')) {
            eval('namespace Seat\Web\Http\Controllers; class Controller {}');
        }
    }

    /** The 13 November case: Amarr through Fuzzwork, priced at Jita a third under Amarr's price. */
    public function test_fuzzwork_is_refused_for_a_market_you_add(): void
    {
        $error = MarketsController::placeError(['provider' => 'fuzzwork', 'provider_slug' => 'amarr'], 'amarr-fuzzwork');

        $this->assertSame(['provider'], array_keys($error));
        $this->assertStringContainsString('Jita 4-4', $error['provider']);
    }

    public function test_fuzzwork_at_a_hub_is_fine(): void
    {
        $this->assertNull(MarketsController::placeError(['provider' => 'fuzzwork'], 'dodixie'));
    }

    public function test_janice_takes_its_hub_from_the_slug(): void
    {
        $this->assertNull(MarketsController::placeError(['provider' => 'janice', 'provider_slug' => 'dodixie'], 'dodixie-janice'));
        $this->assertNull(MarketsController::placeError(['provider' => 'janice', 'provider_slug' => 'Hek'], 'hek-janice'));
    }

    public function test_janice_without_a_hub_is_refused(): void
    {
        foreach ([null, '', 'the-forge', 'C-J6MT'] as $slug) {
            $error = MarketsController::placeError(['provider' => 'janice', 'provider_slug' => $slug], 'my-market');

            $this->assertSame(['provider_slug'], array_keys($error ?? []), var_export($slug, true));
        }
    }

    public function test_janice_keyed_as_a_hub_needs_no_slug(): void
    {
        $this->assertNull(MarketsController::placeError(['provider' => 'janice'], 'rens'));
    }

    /** Only Fuzzwork and Janice fall back to Jita without saying so. */
    public function test_other_providers_are_not_checked_here(): void
    {
        foreach (['esi', 'goonpraisal', 'seat'] as $provider) {
            $this->assertNull(MarketsController::placeError(['provider' => $provider, 'provider_slug' => ''], 'my-market'), $provider);
        }
    }
}
