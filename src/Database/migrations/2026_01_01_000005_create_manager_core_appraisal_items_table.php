<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateManagerCoreAppraisalItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('manager_core_appraisal_items')) {
            Schema::create('manager_core_appraisal_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('appraisal_id')->constrained('manager_core_appraisals')->onDelete('cascade');
                $table->integer('type_id');
                $table->string('type_name', 255)->nullable();
                $table->bigInteger('quantity')->default(0);

                // Volume data
                $table->decimal('type_volume', 20, 4)->default(0);
                $table->decimal('total_volume', 20, 4)->default(0);

                // Pricing data (stored as JSON)
                $table->json('prices')->nullable();

                // Item metadata
                $table->boolean('is_fitted')->default(false);
                $table->boolean('is_bpc')->default(false);
                $table->integer('bpc_runs')->nullable();
                $table->string('location', 255)->nullable();
                $table->json('extra_data')->nullable();

                $table->timestamps();

                // Indexes
                $table->index('appraisal_id');
                $table->index('type_id');
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
        Schema::dropIfExists('manager_core_appraisal_items');
    }
}
