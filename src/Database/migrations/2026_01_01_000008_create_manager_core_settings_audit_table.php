<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log for manager_core_settings writes.
 *
 * Every change to a settings row (via the admin UI, an API call, or an
 * internal call site) writes a row here with the old/new value, who made
 * the change, where it came from, and the source IP. This is forensic
 * evidence — rows are NOT updated or deleted in normal operation; only
 * the cleanup-events command's age-based prune removes old entries.
 *
 * Originally introduced as part of the L14 audit pass.
 */
class CreateManagerCoreSettingsAuditTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('manager_core_settings_audit')) {
            Schema::create('manager_core_settings_audit', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('user_name', 100)->nullable();
                $table->string('setting_key', 150);
                $table->text('old_value')->nullable();
                $table->text('new_value')->nullable();
                $table->string('source', 50)->default('settings_ui');
                $table->string('ip', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('setting_key', 'mc_audit_key');
                $table->index('created_at', 'mc_audit_created');
                $table->index('user_id', 'mc_audit_user');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_core_settings_audit');
    }
}
