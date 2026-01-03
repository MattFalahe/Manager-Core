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

                // Pricing
                $table->decimal('unit_price', 20, 2)->default(0);
                $table->decimal('total_price', 20, 2)->default(0);

                // Metadata
                $table->boolean('price_available')->default(true);
                $table->json('metadata')->nullable();

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
