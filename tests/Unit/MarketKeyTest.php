<?php

namespace ManagerCore\Tests\Unit;

use ManagerCore\Models\Market;
use PHPUnit\Framework\TestCase;

/**
 * A market's key is stored in every table that names a market, so a new key
 * is never longer than the narrowest of those columns.
 */
class MarketKeyTest extends TestCase
{
    private static function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/src/' . $path);
    }

    /** The width a migration gives a table's market column. */
    private static function marketColumn(string $migration): int
    {
        $file = glob(dirname(__DIR__, 2) . "/src/Database/migrations/*_{$migration}.php")[0];

        preg_match("/string\('market', (\d+)\)/", file_get_contents($file), $found);

        return (int) $found[1];
    }

    public function test_a_key_fits_every_column_that_stores_it(): void
    {
        $this->assertSame(Market::KEY_MAX_LENGTH, self::marketColumn('create_manager_core_pricing_preferences_table'));

        foreach (['market_prices', 'price_history', 'type_subscriptions'] as $table) {
            $this->assertGreaterThanOrEqual(
                Market::KEY_MAX_LENGTH,
                self::marketColumn("create_manager_core_{$table}_table"),
                $table
            );
        }
    }

    public function test_a_short_key_is_left_as_it_is(): void
    {
        $this->assertSame('c-j6mt-1st-taj-mahgoon', Market::fitKey('c-j6mt-1st-taj-mahgoon'));
    }

    /** "Perimeter - Tranquility Trading Tower" makes 35 characters. */
    public function test_a_long_key_is_cut_to_what_the_columns_hold(): void
    {
        $key = Market::fitKey('perimeter-tranquility-trading-tower');

        $this->assertSame('perimeter-tranquility-trading-to', $key);
        $this->assertSame(32, strlen($key));
    }

    public function test_a_cut_key_does_not_end_on_a_separator(): void
    {
        // The 32nd character is the hyphen after "hub".
        $this->assertSame('ualx-3-mothership-bellicose-hub', Market::fitKey('ualx-3-mothership-bellicose-hub-one'));
    }

    public function test_the_markets_form_and_the_made_key_use_the_limit(): void
    {
        $controller = self::source('Http/Controllers/MarketsController.php');

        $this->assertStringContainsString("'nullable|string|max:' . Market::KEY_MAX_LENGTH", $controller);
        $this->assertStringContainsString('Market::fitKey(Str::slug($label))', $controller);
        $this->assertStringContainsString(
            'maxlength="{{ \ManagerCore\Models\Market::KEY_MAX_LENGTH }}"',
            self::source('Resources/views/markets/form.blade.php')
        );
    }

    /** A market saved with a longer key before the limit can still be edited. */
    public function test_saving_a_change_to_a_market_does_not_check_its_key(): void
    {
        $this->assertMatchesRegularExpression(
            '/if \(\$existing !== null\) \{\s+unset\(\$rules\[\'key\'\]\);/',
            self::source('Http/Controllers/MarketsController.php')
        );
    }
}
