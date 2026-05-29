<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot of an ESI poll/sweep worker's in-memory registry state at the
 * moment the job started.
 *
 * Written by PollEsiNotifications and SweepSeatNotifications. Read by the
 * Plugin Bridge dashboard to show operators what the queue worker actually
 * sees (which can differ from what an HTTP context sees because the
 * EsiNotificationRegistry is per-process).
 */
class WorkerRegistrySnapshot extends Model
{
    protected $table = 'manager_core_worker_registry_snapshot';

    // We only write created_at; updates are never expected on this table.
    // Disabling updated_at avoids the missing-column error from Eloquent's
    // default timestamps() behaviour.
    public $timestamps = false;

    protected $fillable = [
        'job_class',
        'handlers_count',
        'types_count',
        'plugins_seen',
        'key_pool_size',
        'outcome',
        'created_at',
    ];

    protected $casts = [
        'handlers_count' => 'integer',
        'types_count' => 'integer',
        'key_pool_size' => 'integer',
        'plugins_seen' => 'array',
        'outcome' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Latest snapshot per job class. Returns a map keyed by job_class.
     * Used by the dashboard panel.
     *
     * @return array<string, self>
     */
    public static function latestByJobClass(): array
    {
        // Subquery to find the max created_at per job_class, then join back
        // to fetch the matching rows. Same shape as `latest of group` in SQL.
        $maxIds = static::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('job_class')
            ->pluck('id');

        return static::query()
            ->whereIn('id', $maxIds)
            ->get()
            ->keyBy('job_class')
            ->all();
    }

    /**
     * Health status for the dashboard. Returns one of:
     *   'healthy'  — handlers registered, key pool populated, recent
     *   'warning'  — handlers registered but key pool empty, OR snapshot is older than 5 minutes
     *   'error'    — zero handlers (the smoking-gun case from the 2026-05-11 session)
     *   'stale'    — snapshot older than 1 hour
     */
    public function getHealthAttribute(): string
    {
        $ageSeconds = $this->created_at?->diffInSeconds(now()) ?? PHP_INT_MAX;

        if ($ageSeconds > 3600) {
            return 'stale';
        }

        if ($this->handlers_count === 0) {
            return 'error';
        }

        if ($this->key_pool_size === 0 || $ageSeconds > 300) {
            return 'warning';
        }

        return 'healthy';
    }
}
