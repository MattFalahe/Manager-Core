<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateManagerCorePriceHistoryTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('manager_core_price_history')) {
            Schema::create('manager_core_price_history', function (Blueprint $table) {
                $table->id();
                $table->integer('type_id')->index();
                $table->string('market', 50)->index();
                $table->date('date')->index();

                // Daily price statistics
                $table->decimal('avg_buy', 20, 4)->default(0);
                $table->decimal('avg_sell', 20, 4)->default(0);
                $table->decimal('max_buy', 20, 4)->default(0);
                $table->decimal('min_sell', 20, 4)->default(0);
                $table->bigInteger('total_volume')->default(0);

                $table->timestamps();

                // Unique constraint - one record per type/market/date
                $table->unique(['type_id', 'market', 'date']);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('manager_core_price_history');
    }
}
