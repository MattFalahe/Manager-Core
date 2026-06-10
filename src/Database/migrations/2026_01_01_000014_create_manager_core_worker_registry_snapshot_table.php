<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worker registry snapshot table.
 *
 * Each time an ESI poll/sweep job runs, it writes a row describing the state
 * of the in-memory EsiNotificationRegistry it observed at the start of its
 * own handle() — how many handlers were registered, which plugins
 * contributed types, and how many key holders the pool currently had.
 *
 * Why this exists: ESI notification handlers register in a per-process
 * in-memory singleton. There's no way from an HTTP-context page (like the
 * bridge dashboard) to know what state the QUEUE WORKER's registry is in.
 * The 2026-05-11 debug session burned hours hunting an empty-registry bug
 * in the worker that wasn't visible from the admin view. With snapshots,
 * the bridge dashboard can show "Last poll snapshot: 23 handlers from 1
 * plugin, 5 enabled key holders" and operators see the problem visually.
 *
 * Retention is bounded — only the latest snapshot per job_class matters
 * for operational visibility. Older rows are kept briefly for trend
 * inspection then pruned by the existing cleanup-events cron.
 */
class CreateManagerCoreWorkerRegistrySnapshotTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('manager_core_worker_registry_snapshot')) {
            Schema::create('manager_core_worker_registry_snapshot', function (Blueprint $table) {
                $table->id();

                // Which job wrote this snapshot. Same string used in Horizon's
                // job listing so operators can correlate directly.
                $table->string('job_class', 200);

                // Number of ESI notification handlers registered with the in-memory
                // EsiNotificationRegistry at the moment this job started. A value
                // of 0 means no plugin has registered handlers in this worker
                // process yet — almost certainly a bug if any consumer plugin is
                // installed.
                $table->unsignedInteger('handlers_count')->default(0);

                // Distinct CCP notification types covered by registered handlers
                // (sum across plugins). Effectively the breadth of detection
                // available in this worker.
                $table->unsignedInteger('types_count')->default(0);

                // Distinct plugin keys that contributed at least one handler.
                // e.g. ['structure-manager', 'mining-manager']. JSON encoded.
                $table->json('plugins_seen')->nullable();

                // How many enabled (eligible-to-poll) key holders the shared pool
                // had at job start. Independent of registry state — operators can
                // see "pool=0" or "pool=5" and immediately know whether the
                // bottleneck is "nobody registered handlers" or "no key holders".
                $table->unsignedInteger('key_pool_size')->default(0);

                // Optional metadata: outcome counters from the job run itself
                // (notifications discovered, dispatched, errors). Useful for
                // operators trying to correlate "did the job actually find
                // anything" with the registry state at start.
                $table->json('outcome')->nullable();

                $table->timestamp('created_at')->useCurrent();

                // Index for the "latest snapshot per job_class" lookup that
                // powers the dashboard panel.
                $table->index(['job_class', 'created_at'], 'mc_wrs_job_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_core_worker_registry_snapshot');
    }
}
