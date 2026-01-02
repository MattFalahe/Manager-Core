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
        Schema::create('manager_core_appraisals', function (Blueprint $table) {
            $table->id();
            $table->string('appraisal_id', 50)->unique(); // Public-facing ID
            $table->bigInteger('user_id')->nullable()->index();
            $table->string('market', 50)->default('jita');
            $table->string('kind', 50)->nullable(); // cargo_scan, assets, contract, etc.

            // Totals
            $table->decimal('total_buy', 20, 2)->default(0);
            $table->decimal('total_sell', 20, 2)->default(0);
            $table->decimal('total_volume', 20, 2)->default(0);

            // Raw input data
            $table->text('raw_input')->nullable();

            // Pricing configuration used
            $table->decimal('price_percentage', 5, 2)->default(100);

            // Metadata
            $table->json('parser_info')->nullable(); // Which parsers matched
            $table->json('unparsed_lines')->nullable(); // Lines that couldn't be parsed

            // Privacy
            $table->boolean('is_private')->default(false);
            $table->string('private_token', 100)->nullable();

            $table->timestamps();
            $table->timestamp('expires_at')->nullable();

            // Indexes
            $table->index('user_id');
            $table->index('created_at');
            $table->index('expires_at');
        });
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
};
