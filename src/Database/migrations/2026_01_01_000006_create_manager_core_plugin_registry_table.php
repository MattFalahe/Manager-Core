<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateManagerCorePluginRegistryTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('manager_core_plugin_registry')) {
            Schema::create('manager_core_plugin_registry', function (Blueprint $table) {
                $table->id();
                $table->string('plugin_name', 100)->unique();
                $table->string('plugin_class', 255);
                $table->string('version', 20)->nullable();
                $table->json('capabilities')->nullable(); // Array of capability names
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                // Indexes
                $table->index('is_active');
                $table->index('last_seen_at');
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
        Schema::dropIfExists('manager_core_plugin_registry');
    }
}
