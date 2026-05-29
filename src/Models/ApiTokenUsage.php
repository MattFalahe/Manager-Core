<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * L22: append-only forensic log of API token usage.
 *
 * Each authenticated API request inserts a row here (lightweight — token_id,
 * IP, endpoint, method, status). Pruned by the cleanup-events command.
 *
 * Provides the missing "where has this token been used" view that the
 * single last_used_at/last_used_ip columns on ApiToken can't give us.
 */
class ApiTokenUsage extends Model
{
    protected $table = 'manager_core_api_token_usage';

    public $timestamps = false;

    protected $fillable = [
        'token_id',
        'ip',
        'endpoint',
        'method',
        'status_code',
        'created_at',
    ];

    protected $casts = [
        'token_id' => 'integer',
        'status_code' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Throttled writer used by ApiTokenAuth middleware. Only writes a new
     * row if no row exists for this (token_id, ip, endpoint, status_code)
     * within the last second — prevents bot-driven storms from filling the
     * table with identical rows.
     */
    public static function recordIfNew(int $tokenId, ?string $ip, ?string $endpoint, ?string $method, ?int $statusCode = null): void
    {
        try {
            // Lightweight in-process dedup window (1 second) — relies on the
            // cache layer rather than a DB read on every request.
            $cacheKey = 'mc_api_usage_' . md5($tokenId . '|' . ($ip ?? '') . '|' . ($endpoint ?? '') . '|' . ($method ?? ''));
            if (\Illuminate\Support\Facades\Cache::get($cacheKey)) {
                return;
            }
            \Illuminate\Support\Facades\Cache::put($cacheKey, 1, 5);

            static::create([
                'token_id' => $tokenId,
                'ip' => $ip ? substr($ip, 0, 45) : null,
                'endpoint' => $endpoint ? substr($endpoint, 0, 200) : null,
                'method' => $method ? strtoupper(substr($method, 0, 10)) : null,
                'status_code' => $statusCode,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal — never let usage logging break a real API request
        }
    }
}
