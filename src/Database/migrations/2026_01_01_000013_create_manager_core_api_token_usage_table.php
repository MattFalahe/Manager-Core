<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only forensic log of API token usage.
 *
 * Every authenticated REST request logs a row here: which token, from
 * which IP, hitting which endpoint, with what HTTP status. Used by the
 * admin diagnostic UI to show "this token was used 423 times in the
 * last 24h" and to investigate suspected token compromise.
 *
 * Intentionally NO foreign key on token_id:
 *   - These rows are forensic evidence; we DON'T want them to disappear
 *     when an operator deletes a compromised/expired token. The cleanup
 *     command's age-based prune is the canonical retention policy.
 *   - JOIN-based reports that need only "live token" usage rows should
 *     LEFT JOIN api_tokens and filter on api_tokens.id IS NOT NULL.
 *
 * Originally introduced as part of the L22 audit pass.
 */
class CreateManagerCoreApiTokenUsageTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('manager_core_api_token_usage')) {
            Schema::create('manager_core_api_token_usage', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('token_id');
                $table->string('ip', 45)->nullable();
                $table->string('endpoint', 200)->nullable();
                $table->string('method', 10)->nullable();
                $table->unsignedSmallInteger('status_code')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['token_id', 'created_at'], 'mc_api_usage_token_date');
                $table->index('created_at', 'mc_api_usage_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_core_api_token_usage');
    }
}
