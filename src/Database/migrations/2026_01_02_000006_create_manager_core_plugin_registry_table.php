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
        Schema::create('manager_core_plugin_registry', function (Blueprint $table) {
            $table->id();
            $table->string('plugin_name', 100)->unique();
            $table->string('plugin_class', 255);
            $table->string('version', 50)->nullable();
            $table->boolean('is_active')->default(true);

            // Capabilities this plugin provides
            $table->json('capabilities')->nullable();

            // Plugin metadata
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('manager_core_plugin_registry');
    }
};
