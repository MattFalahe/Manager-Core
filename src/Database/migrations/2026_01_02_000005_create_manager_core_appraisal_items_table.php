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
        Schema::create('manager_core_appraisal_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appraisal_id')->index();
            $table->integer('type_id')->index();
            $table->string('type_name', 255);
            $table->bigInteger('quantity');

            // Volume
            $table->decimal('type_volume', 20, 4)->default(0);
            $table->decimal('total_volume', 20, 4)->default(0);

            // Prices (stored as JSON for flexibility)
            $table->json('prices'); // Contains buy/sell stats

            // Item metadata (from parsers)
            $table->boolean('is_fitted')->default(false);
            $table->boolean('is_bpc')->default(false);
            $table->integer('bpc_runs')->nullable();
            $table->string('location')->nullable();
            $table->json('extra_data')->nullable(); // Other parser-specific data

            $table->timestamps();

            // Foreign key
            $table->foreign('appraisal_id')
                  ->references('id')
                  ->on('manager_core_appraisals')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('manager_core_appraisal_items');
    }
};
