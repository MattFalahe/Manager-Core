<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('manager_core_market_prices', function (Blueprint $table) {
            $table->id();
            $table->integer('type_id')->index();
            $table->string('market', 50)->index(); // jita, amarr, etc.
            $table->string('price_type', 10); // buy, sell

            // Price statistics
            $table->decimal('price_min', 20, 2)->default(0);
            $table->decimal('price_max', 20, 2)->default(0);
            $table->decimal('price_avg', 20, 2)->default(0);
            $table->decimal('price_median', 20, 2)->default(0);
            $table->decimal('price_percentile', 20, 2)->default(0);
            $table->decimal('price_stddev', 20, 2)->default(0);

            // Volume and order data
            $table->bigInteger('volume')->default(0);
            $table->integer('order_count')->default(0);

            // Metadata
            $table->string('strategy', 50)->default('orders'); // orders, ccp, universe
            $table->timestamp('updated_at');

            // Indexes
            $table->unique(['type_id', 'market', 'price_type']);
            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('manager_core_market_prices');
    }
};
