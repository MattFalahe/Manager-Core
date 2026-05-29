<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ManagerCore\Models\Market;

/**
 * Market locations MC can fetch prices for.
 *
 * Two market types:
 *
 *   1. HUB markets (Jita, Amarr, Dodixie, Hek, Rens, custom regional hubs)
 *      Use ESI's public /markets/{region_id}/orders/?type_id=X endpoint.
 *      No auth required. Type-filterable, fast, no pagination bugs.
 *
 *   2. CITADEL markets (player-owned structures in nullsec)
 *      Use a third-party appraisal service (Goonpraisal, Janice, etc.).
 *      MC's own ESI scrape of /markets/structures/{id}/orders/ was
 *      attempted in earlier versions but CCP's pagination is unreliable
 *      on large nullsec hubs (X-Pages reports N pages, but pages 2..N
 *      return identical content to page 1). The pivot to third-party
 *      providers in 2026-05 is what makes citadel pricing actually work.
 *
 * Provider routing:
 *
 *   - `provider` column on each market row selects which PriceProvider
 *     subclass handles pricing for that market. Valid values mirror
 *     PricingService::getPriceProvider() — 'esi' (a.k.a. MCPraisal),
 *     'janice', 'fuzzwork', 'goonpraisal', 'seat'.
 *
 *   - `provider_slug` carries the provider-specific market identifier.
 *     For Goonpraisal that's 'insmother' / 'tenerifis' / 'catch' / etc.
 *     For ESI it's typically null (region_id + system_ids do the work).
 *     For Janice it would be the Janice market name (e.g. 'jita').
 *
 * Per-plugin market selection (which market a plugin like Buyback Manager
 * prefers) is handled by `manager_core_pricing_preferences`, so different
 * plugins can prefer different markets simultaneously.
 */
class CreateManagerCoreMarketsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('manager_core_markets')) {
            return;
        }

        Schema::create('manager_core_markets', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('key')->unique();   // Operator-chosen slug, e.g. 'jita' / 'c-j6mt'
            $table->string('name');            // Display name, e.g. 'Jita (The Forge)'

            // Type discriminator. Drives some UI affordances + the provider
            // dropdown defaults.
            $table->string('market_type', 16)->default('hub');

            // Provider routing. Default 'esi' covers the seeded hub markets.
            // Citadel markets seeded below override this to point at the
            // appropriate third-party provider.
            $table->string('provider', 32)->default('esi');

            // Provider-specific market identifier. NULL for ESI providers
            // (region_id + system_ids do the lookup). Required for
            // Goonpraisal / Janice / etc. — see provider class docs for
            // their slug vocabularies.
            $table->string('provider_slug', 64)->nullable();

            // For ESI / hub markets — which region's order book to query
            // and which solar systems within that region to filter to.
            $table->bigInteger('region_id');
            $table->json('system_ids');

            // Display metadata for citadel markets routed through third-party
            // providers. Operator types these in; we don't auto-resolve them
            // anymore since we're not authenticating against the structure.
            $table->string('structure_name', 255)->nullable();
            $table->string('system_name', 64)->nullable();
            $table->bigInteger('system_id')->unsigned()->nullable();

            // Lifecycle
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_custom')->default(false);

            // Refresh tracking. Set by whatever provider runs the most
            // recent fetch — useful for showing "last updated X ago" in
            // the admin UI regardless of which provider is configured.
            $table->timestamp('last_refresh_at')->nullable();
            $table->string('last_refresh_status', 32)->nullable();
            $table->text('last_refresh_error')->nullable();

            $table->timestamps();

            $table->index(['is_enabled', 'is_custom']);
            $table->index(['market_type', 'is_enabled'], 'mc_markets_type_enabled');
            $table->index('provider');
        });

        $this->seedDefaultMarkets();
    }

    /**
     * Seed the default market set. Each install gets the 5 hub markets
     * pre-configured for Fuzzwork, plus the 7 Goonpraisal-tracked nullsec
     * hubs pre-configured for the Goonpraisal provider. Operators can
     * disable any of these (or delete the custom ones) via the Markets
     * admin page.
     *
     * Why Fuzzwork is the hub default (not MCPraisal/ESI):
     *   - Fuzzwork returns the entire market in a single bulk JSON dump
     *     (~50ms for the full region). MCPraisal needs one ESI call per
     *     type — much slower at the per-type-count Mining Manager needs.
     *   - Fuzzwork uses zero ESI quota. MCPraisal eats into the
     *     per-app-key error budget every refresh.
     *   - Operators who want fresh-from-CCP data can flip any hub market
     *     to provider='esi' (MCPraisal) via the per-market routing on
     *     the Markets admin page.
     *
     * Note: the Goonpraisal markets are dormant (is_enabled=false) until
     * the operator opts in via the Markets admin page toggle.
     */
    protected function seedDefaultMarkets()
    {
        $hubs = [
            'jita'    => ['Jita (The Forge)',    10000002, [30000142]],
            'amarr'   => ['Amarr (Domain)',      10000043, [30002187]],
            'dodixie' => ['Dodixie (Sinq Laison)', 10000032, [30002659]],
            'hek'     => ['Hek (Metropolis)',    10000042, [30002053]],
            'rens'    => ['Rens (Heimatar)',     10000030, [30002510]],
        ];
        foreach ($hubs as $key => $info) {
            Market::create([
                'key'         => $key,
                'name'        => $info[0],
                'market_type' => 'hub',
                'provider'    => 'fuzzwork',
                'region_id'   => $info[1],
                'system_ids'  => $info[2],
                'is_enabled'  => true,
                'is_custom'   => false,
            ]);
        }

        // Goonpraisal-tracked nullsec markets. provider='goonpraisal',
        // provider_slug = Goonpraisal's actual market identifier as
        // expected by their API. Verified by inspecting the <option value=>
        // attributes on https://appraise.gnf.lt/ on 2026-05-27 — citadel
        // markets use the structure system code (uppercase, hyphenated),
        // hub markets use lowercase region/system names.
        $goonMarkets = [
            'insmother-c-j6mt'   => ['Insmother (C-J6MT)',       10000009, 30000772, 'C-J6MT', 'C-J6MT'],
            'insmother-lawn'     => ['Insmother LAWN (GB-6X5)',  10000009, null,     'GB-6X5', 'GB-6X5'],
            'tenerifis-ualx3'    => ['Tenerifis (UALX-3)',       10000016, null,     'UALX-3', 'UALX-3'],
            'catch-hy-rwo'       => ['Catch (HY-RWO)',           10000014, null,     'HY-RWO', 'HY-RWO'],
            'paragon-soul-o4tz5' => ['Paragon Soul (O4T-Z5)',    10000059, null,     'O4T-Z5', 'O4T-Z5'],
            'esoteria-rark'      => ['Esoteria (R-ARKN)',        10000039, null,     'R-ARKN', 'R-ARKN'],
            'immensea-gm0k7'     => ['Immensea (GM-0K7)',        10000025, null,     'GM-0K7', 'GM-0K7'],
        ];
        foreach ($goonMarkets as $key => $info) {
            Market::create([
                'key'            => $key,
                'name'           => $info[0],
                'market_type'    => 'citadel',
                'provider'       => 'goonpraisal',
                'provider_slug'  => $info[4],
                'region_id'      => $info[1],
                'system_ids'     => $info[2] ? [$info[2]] : [],
                'system_name'    => $info[3],
                'is_enabled'     => false,  // Dormant until operator opts in
                'is_custom'      => false,
            ]);
        }
    }

    public function down()
    {
        Schema::dropIfExists('manager_core_markets');
    }
}
