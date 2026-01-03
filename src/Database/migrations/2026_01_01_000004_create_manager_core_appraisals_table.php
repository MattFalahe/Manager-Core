<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateManagerCoreAppraisalsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('manager_core_appraisals')) {
            Schema::create('manager_core_appraisals', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique(); // Short code like "ABC123"
                $table->text('raw_input')->nullable(); // Original input text
                $table->string('market', 50)->default('jita');
                $table->string('price_type', 10)->default('sell'); // buy, sell

                // Totals
                $table->decimal('total_value', 20, 2)->default(0);
                $table->integer('item_count')->default(0);

                // Modifiers (optional)
                $table->decimal('base_percentage', 5, 2)->default(100.00); // e.g., 90% for buyback
                $table->json('modifiers')->nullable(); // Additional modifiers

                // Final value after modifiers
                $table->decimal('final_value', 20, 2)->default(0);

                // Metadata
                $table->integer('user_id')->nullable()->index();
                $table->string('created_by', 100)->nullable(); // plugin name
                $table->timestamps();
                $table->softDeletes();

                // Indexes
                $table->index('created_at');
                $table->index(['market', 'created_at']);
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
        Schema::dropIfExists('manager_core_appraisals');
    }
}
