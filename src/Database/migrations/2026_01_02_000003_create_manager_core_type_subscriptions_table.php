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
        Schema::create('manager_core_type_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('plugin_name', 100)->index();
            $table->integer('type_id')->index();
            $table->string('market', 50)->default('jita');
            $table->integer('priority')->default(1); // For batching priority
            $table->timestamps();

            // Unique constraint - each plugin can only subscribe to a type once per market
            $table->unique(['plugin_name', 'type_id', 'market']);
        });
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
};
