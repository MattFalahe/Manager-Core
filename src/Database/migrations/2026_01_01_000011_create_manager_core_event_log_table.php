<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateManagerCoreEventLogTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('manager_core_event_log')) {
            Schema::create('manager_core_event_log', function (Blueprint $table) {
                $table->id();
                $table->string('event_name', 255);
                $table->string('publisher_plugin', 100);
                // EventBus dedup key (folded from 000013). NOT a unique constraint —
                // dedup is enforced in PHP at publish time within a window, so the
                // same idempotency_key is allowed to re-appear in the log after the
                // window expires (e.g. legitimate retries of an old event).
                $table->string('idempotency_key', 128)->nullable();
                $table->json('payload')->nullable();
                $table->integer('subscriber_count')->default(0);
                $table->string('status', 20)->default('dispatched');
                $table->json('errors')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['event_name', 'created_at'], 'mc_event_log_name_date');
                $table->index('publisher_plugin', 'mc_event_log_publisher');
                // Folded from 000013: scan-by-date for cleanup, status for stats,
                // (publisher, idempotency_key) composite for dedup exists() lookup.
                $table->index('created_at', 'mc_event_log_created');
                $table->index('status', 'mc_event_log_status');
                $table->index(['publisher_plugin', 'idempotency_key'], 'mc_event_log_idempotency');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_core_event_log');
    }
}
