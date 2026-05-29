<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-plugin pricing preferences.
 *
 * Manager Core hosts pricing for any plugin that opts in. Each consumer
 * plugin (Structure Manager Economics, future Mining Manager appraisals,
 * etc.) registers a default preference at boot via the PluginBridge
 * capability `pricing.registerPreference`. Admins can then override per-
 * plugin in MC's admin UI without the plugin needing to expose its own
 * settings page.
 *
 * Pricing source resolution at call time:
 *   1. If a row exists for the plugin_key, use its market + price_type
 *   2. Otherwise fall back to MC's global pricing config
 *
 * Plugins call PricingService::priceForPlugin($pluginKey, $typeId) and
 * the service handles the lookup transparently.
 */
class CreateManagerCorePricingPreferencesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('manager_core_pricing_preferences')) {
            return;
        }

        Schema::create('manager_core_pricing_preferences', function (Blueprint $table) {
            $table->id();

            $table->string('plugin_key', 64)->unique()
                ->comment('Identifier for the consuming plugin (e.g. structure-manager, mining-manager)');

            $table->string('market', 32)->default('jita')
                ->comment('Market key matching manager_core_markets.key (jita, amarr, dodixie, etc.)');

            $table->string('price_type', 16)->default('sell')
                ->comment('sell | buy | avg. avg = midpoint of sell-min and buy-max.');

            // Folded from 000023 (consolidated pre-v1.0.0 release): per-plugin
            // override of the market provider routing. Null = use the
            // market's configured provider (default; the only behavior
            // before Option B). Non-null = route THIS plugin through the
            // named provider via a live upstream fetch (bypasses MC's
            // local cache because the cache is keyed on type_id+market+
            // price_type with no provider column). Use case: MM via Janice
            // for Jita tax accuracy while SM continues through Fuzzwork.
            // Valid values: esi | janice | fuzzwork | goonpraisal | seat.
            $table->string('provider_override', 32)->nullable()
                ->comment('Per-plugin override of the market provider routing. Null = use markets.provider. Values: esi, janice, fuzzwork, goonpraisal, seat.');

            $table->boolean('admin_overridden')->default(false)
                ->comment('True when an MC admin has changed this from the plugins registered default. False when still on the plugin default (lets the plugin update the default at boot without overwriting admin choices).');

            $table->text('notes')->nullable()
                ->comment('Free-text label like Plugin default: Jita sell or Admin override 2026-05');

            $table->timestamps();

            $table->index('plugin_key', 'mcpp_plugin_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('manager_core_pricing_preferences');
    }
}
