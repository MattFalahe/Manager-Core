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
                $table->string('appraisal_id', 20)->unique(); // Short code like "ABC123"
                $table->integer('user_id')->nullable();
                $table->string('market', 50)->default('jita');
                // Folded from 000021 (consolidated pre-v1.0.0 release): per-appraisal
                // record of which pricing backend served this appraisal — mcpraisal
                // / janice / fuzzwork / goonpraisal / seat. Nullable; null = legacy
                // appraisal predating per-appraisal provider selection (treated as
                // "market provider" by the show view).
                $table->string('price_provider', 32)->nullable();
                $table->string('kind', 50)->nullable(); // cargo, listing, etc.

                // Totals
                $table->decimal('total_buy', 20, 2)->default(0);
                $table->decimal('total_sell', 20, 2)->default(0);
                $table->decimal('total_volume', 20, 2)->default(0);

                // Input and parsing
                $table->text('raw_input')->nullable(); // Original input text
                $table->decimal('price_percentage', 5, 2)->default(100.00); // e.g., 90% for quick sale
                $table->json('parser_info')->nullable(); // Parser metadata
                $table->json('unparsed_lines')->nullable(); // Lines that couldn't be parsed

                // Privacy and expiration
                $table->boolean('is_private')->default(false);
                $table->string('private_token', 64)->nullable();
                $table->timestamp('expires_at')->nullable();

                // Metadata
                $table->timestamps();
                $table->softDeletes();

                // Indexes
                $table->index('user_id');
                $table->index('created_at');
                $table->index(['market', 'created_at']);

                // Folded from 000009: speeds up "my appraisals" listings and
                // expiry-prune queries respectively.
                $table->index(['user_id', 'created_at'], 'mc_appraisals_user_created_idx');
                $table->index('expires_at', 'mc_appraisals_expires_idx');
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
