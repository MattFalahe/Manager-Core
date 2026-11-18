<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One provider's price for an item at a market, for a plugin that asked for
// that provider by name. A market's own prices stay in
// manager_core_market_prices, which every plugin reads and whose key has no
// provider in it, so a second provider's price stored there would replace
// the market's own for everyone.
class CreateManagerCoreMarketPriceVariantsTable extends Migration
{
    private const TABLE = 'manager_core_market_price_variants';

    public function up()
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->integer('type_id');
                $table->string('market', 50)
                    ->comment('Market key, as in manager_core_markets');
                $table->string('provider', 16)
                    ->comment('esi, fuzzwork, janice or goonpraisal');
                $table->string('price_type', 10)
                    ->comment('buy or sell');

                $table->decimal('price_min', 20, 2)->default(0);
                $table->decimal('price_max', 20, 2)->default(0);
                $table->decimal('price_avg', 20, 2)->default(0);
                $table->decimal('price_median', 20, 2)->default(0);
                $table->decimal('price_percentile', 20, 2)->default(0);
                $table->decimal('price_stddev', 20, 2)->default(0);
                $table->bigInteger('volume')->default(0);
                $table->integer('order_count')->default(0);

                $table->string('strategy', 50)->default('orders');
                $table->timestamp('updated_at')->nullable();
            });
        }

        // Laravel adds a table's indexes after creating it, so a run that
        // stopped in between leaves the table without them.
        if (!$this->hasIndex('mc_price_variants_unique')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(['type_id', 'market', 'provider', 'price_type'], 'mc_price_variants_unique');
            });
        }

        if (!$this->hasIndex('mc_price_variants_market_provider_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['market', 'provider'], 'mc_price_variants_market_provider_idx');
            });
        }

        if (!$this->hasIndex('mc_price_variants_updated_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index('updated_at', 'mc_price_variants_updated_idx');
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
