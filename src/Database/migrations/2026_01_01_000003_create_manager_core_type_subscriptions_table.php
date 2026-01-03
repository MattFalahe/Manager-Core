<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateManagerCoreTypeSubscriptionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('manager_core_type_subscriptions')) {
            Schema::create('manager_core_type_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->string('plugin_name', 100)->index();
                $table->integer('type_id')->index();
                $table->string('market', 50)->default('jita');
                $table->integer('priority')->default(1); // Higher = more important
                $table->timestamps();

                // Unique constraint - each plugin can subscribe to a type/market once
                $table->unique(['plugin_name', 'type_id', 'market']);
                $table->index(['market', 'priority']);
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
        Schema::dropIfExists('manager_core_type_subscriptions');
    }
}
