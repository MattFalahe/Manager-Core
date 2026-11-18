<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Which plugin wants which item at which market through which provider. The
// scheduled refresh fetches each of these into
// manager_core_market_price_variants. manager_core_type_subscriptions is
// keyed without a provider, so it can't hold two providers for one item.
class CreateManagerCoreVariantSubscriptionsTable extends Migration
{
    private const TABLE = 'manager_core_variant_subscriptions';

    public function up()
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->string('plugin_name', 100);
                $table->integer('type_id');
                $table->string('market', 50)
                    ->comment('Market key, as in manager_core_markets');
                $table->string('provider', 16)
                    ->comment('esi, fuzzwork, janice or goonpraisal');
                $table->integer('priority')->default(1);
                $table->timestamps();
            });
        }

        // Laravel adds a table's indexes after creating it, so a run that
        // stopped in between leaves the table without them.
        if (!$this->hasIndex('mc_var_subs_unique')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(['plugin_name', 'type_id', 'market', 'provider'], 'mc_var_subs_unique');
            });
        }

        if (!$this->hasIndex('mc_var_subs_market_provider_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['market', 'provider', 'type_id'], 'mc_var_subs_market_provider_idx');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function hasIndex(string $index): bool
    {
        try {
            return DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', self::TABLE)
                ->where('INDEX_NAME', $index)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
