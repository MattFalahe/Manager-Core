<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-plugin pricing preference.
 *
 * One row per consumer plugin. Admins can edit market + price_type
 * + provider_override via MC's admin UI; consumer plugins set their
 * default at boot via `PluginBridge::registerPricingPreference()`.
 *
 * @property int    $id
 * @property string $plugin_key         Unique identifier for the consumer plugin
 * @property string $market             Market key (jita, amarr, etc.)
 * @property string $price_type         'sell' | 'buy' | 'avg'
 * @property string|null $provider_override Optional per-plugin override of the market's provider. Null = use markets.provider. Values: esi, janice, fuzzwork, goonpraisal, seat.
 * @property bool   $admin_overridden   True if admin edited away from plugin default
 * @property string|null $notes
 */
class PricingPreference extends Model
{
    protected $table = 'manager_core_pricing_preferences';

    protected $fillable = [
        'plugin_key',
        'market',
        'price_type',
        'provider_override',
        'admin_overridden',
        'notes',
    ];

    protected $casts = [
        'admin_overridden' => 'boolean',
    ];

    /**
     * Allowed price types. Kept here so the admin UI dropdown and the
     * PricingService both use the same list.
     */
    public const PRICE_TYPES = ['sell', 'buy', 'avg'];

    /**
     * Allowed provider overrides. Mirror of PricingService::getPriceProvider
     * switch arms + MarketsController::VALID_PROVIDERS. Empty string /
     * null means "use the market's configured provider".
     */
    public const VALID_PROVIDER_OVERRIDES = ['esi', 'janice', 'fuzzwork', 'goonpraisal', 'seat'];

    /**
     * Look up a preference by plugin key. Returns null when the plugin
     * has not registered a preference yet (PricingService falls back
     * to MC's global default).
     */
    public static function forPlugin(string $pluginKey): ?self
    {
        return static::query()->where('plugin_key', $pluginKey)->first();
    }

    /**
     * Apply a default for a plugin without overwriting an admin override.
     *
     * If no row exists, inserts one with admin_overridden=false.
     * If a row exists with admin_overridden=true, leaves it alone.
     * If a row exists with admin_overridden=false, refreshes it to
     * the new default (lets plugins evolve their default cleanly).
     */
    public static function registerDefault(string $pluginKey, string $market, string $priceType, ?string $notes = null): self
    {
        $existing = static::forPlugin($pluginKey);

        if ($existing !== null && $existing->admin_overridden) {
            return $existing;
        }

        $defaultNotes = $notes ?? sprintf('Plugin default: %s %s', $market, $priceType);

        if ($existing === null) {
            return static::create([
                'plugin_key'       => $pluginKey,
                'market'           => $market,
                'price_type'       => $priceType,
                'admin_overridden' => false,
                'notes'            => $defaultNotes,
            ]);
        }

        // Plugin updated its default and admin has not overridden.
        $existing->fill([
            'market'     => $market,
            'price_type' => $priceType,
            'notes'      => $defaultNotes,
        ])->save();

        return $existing;
    }

    /**
     * Mark a preference as admin-overridden. Called from the admin UI
     * when an operator edits the row.
     */
    public function markAdminOverridden(): self
    {
        $this->admin_overridden = true;
        if ($this->notes === null || str_starts_with((string) $this->notes, 'Plugin default:')) {
            $this->notes = sprintf(
                'Admin override %s: %s %s',
                now()->toDateString(),
                $this->market,
                $this->price_type
            );
        }
        $this->save();
        return $this;
    }
}
