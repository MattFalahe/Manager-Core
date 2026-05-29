<?php

namespace ManagerCore\Models\ESI;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Represents a director character in the shared ESI fast-polling key pool.
 *
 * Admins assign characters to this pool via Manager Core's admin UI.
 * The shared polling job round-robins through enabled key holders,
 * tracking per-character health and skipping failed/expired ones.
 *
 * Every plugin that registers a handler with EsiNotificationRegistry
 * benefits from the same shared pool — one set of directors serves
 * notifications for Structure Manager, Mining Manager, HR Manager, etc.
 */
class EsiKeyHolder extends Model
{
    protected $table = 'manager_core_esi_key_holders';

    protected $fillable = [
        'character_id',
        'corporation_id',
        'character_name',
        'enabled',
        'last_polled_at',
        'last_poll_status',
        'failure_category',
        'last_error',
        'consecutive_failures',
        'suspended_until',
        'total_polls',
        'total_notifications_found',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'consecutive_failures' => 'integer',
        'total_polls' => 'integer',
        'total_notifications_found' => 'integer',
        // Date columns. Laravel 11 deprecated the $dates property — datetime
        // casts in $casts are the modern way to get Carbon-hydrated values.
        // Without these, MySQL DATETIME columns come back as raw strings and
        // any ->timestamp or ->diffForHumans() call blows up with
        // "Attempt to read property X on string".
        'last_polled_at'  => 'datetime',
        'suspended_until' => 'datetime',
    ];

    // ============================================================
    // Failure categories
    // ============================================================
    //
    // Set by PollEsiNotifications::categorizeFailure() so the model can
    // pick the right cooldown ladder per failure flavor.
    //
    // The constants are STRING values matching the failure_category column
    // (varchar 30). String enum keeps SQL queries human-readable in the
    // admin UI and forensic logs.

    /** 401 invalid_token — SeAT's master refresh may fix it next cycle.
     *  Cooldown: exponential backoff starting at 30 min, doubling each
     *  failure beyond the 5-strike threshold, capped at 24h. */
    public const CATEGORY_TRANSIENT_AUTH = 'transient_auth';

    /** Refresh token row deleted from SeAT (revoked, PermanentInvalidToken,
     *  or never existed). SeAT can't auto-recover; needs admin attention.
     *  Cooldown: 7 days (basically "park until admin acts"). */
    public const CATEGORY_TERMINAL_AUTH = 'terminal_auth';

    /** 403 / scope missing. The token is valid but lacks
     *  esi-characters.read_notifications.v1. Same long cooldown as terminal —
     *  fixing it requires the operator to re-link the character with the
     *  scope ticked. */
    public const CATEGORY_SCOPE_MISSING = 'scope_missing';

    /** 420 error-limited. Aggressive cooldown to avoid further damage
     *  to the application's error budget. */
    public const CATEGORY_RATE_LIMITED = 'rate_limited';

    /** Timeout, 5xx, connection reset. CCP infrastructure transient.
     *  Cooldown: shorter than auth issues — 10 min starting. */
    public const CATEGORY_NETWORK = 'network';

    /** Catch-all for unrecognized exception shapes. Conservative cooldown. */
    public const CATEGORY_UNKNOWN = 'unknown';

    /**
     * Get enabled key holders for the next poll cycle using a PER-CORP
     * fair LRU rotation.
     *
     * Why per-corp: a 50-director pool with 49 directors in Corp A and
     * 1 director in Corp B should NOT give Corp A 49x more coverage than
     * Corp B. Sov / structure / corp-wide notifications are seen by every
     * director in the relevant corp/alliance, so polling 49 directors from
     * Corp A every cycle is redundant. The "fair" rotation gives each corp
     * at most ONE director per cycle so coverage scales by distinct
     * corp-count, not raw director-count.
     *
     * Algorithm:
     *   1. Filter to eligible characters (enabled + suspension expired/null
     *      + not in $excludeIds)
     *   2. Group by corporation_id
     *   3. Within each corp, pick the least-recently-polled character
     *   4. Sort those per-corp representatives by the corp's oldest poll
     *   5. Take the first $count
     *
     * The rotation gate uses suspended_until (not consecutive_failures) so
     * a character whose cooldown has expired auto-recovers without admin
     * action.
     *
     * @param int $count Maximum characters to return (batch size)
     * @param int[] $excludeIds Character row IDs to exclude from this pass.
     *              Used by PollEsiNotifications cascade-retry so a cascade
     *              chain doesn't repeatedly poll the same chars that just
     *              failed.
     */
    public static function getNextInRotation(int $count = 2, array $excludeIds = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = self::where('enabled', true)
            ->where(function ($q) {
                $q->whereNull('suspended_until')
                  ->orWhere('suspended_until', '<=', Carbon::now());
            });

        if (!empty($excludeIds)) {
            $query->whereNotIn('id', $excludeIds);
        }

        $eligible = $query->get();

        if ($eligible->isEmpty()) {
            return $eligible;
        }

        // Helper: pull a Unix timestamp from last_polled_at regardless of
        // whether the model cast it (Carbon) or it slipped through as a
        // raw string. Belt-and-suspenders defense — the $casts datetime
        // entry above SHOULD always give us a Carbon, but if a queued job
        // serializes/deserializes oddly we don't want a fatal here.
        $tsOf = function ($model): int {
            $v = $model->last_polled_at;
            if ($v === null || $v === '') return 0;
            if ($v instanceof \Carbon\Carbon || $v instanceof \DateTimeInterface) {
                return $v->getTimestamp();
            }
            try {
                return Carbon::parse($v)->getTimestamp();
            } catch (\Throwable $e) {
                return 0;
            }
        };

        // Per-corp LRU: pick the least-recently-polled char per corp_id.
        // Never-polled chars (last_polled_at = NULL → timestamp 0) sort
        // first, which is correct — they haven't had a chance yet.
        $perCorpLru = $eligible->groupBy('corporation_id')->map(function ($charsInCorp) use ($tsOf) {
            return $charsInCorp->sort(function ($a, $b) use ($tsOf) {
                $aTs = $tsOf($a);
                $bTs = $tsOf($b);
                if ($aTs !== $bTs) return $aTs <=> $bTs;
                return $a->id <=> $b->id;
            })->first();
        });

        // Sort the per-corp representatives by oldest poll so the corps
        // that haven't been touched in the longest get picked first.
        $sorted = $perCorpLru->sort(function ($a, $b) use ($tsOf) {
            $aTs = $tsOf($a);
            $bTs = $tsOf($b);
            if ($aTs !== $bTs) return $aTs <=> $bTs;
            return $a->corporation_id <=> $b->corporation_id;
        })->take($count)->values();

        return \Illuminate\Database\Eloquent\Collection::make($sorted->all());
    }

    /**
     * Count distinct enabled, non-suspended corporations in the pool.
     * Used by PollEsiNotifications::computeBatchSize to ensure each cycle
     * can cover every corp when possible.
     */
    public static function countEligibleCorps(): int
    {
        return (int) self::where('enabled', true)
            ->where(function ($q) {
                $q->whereNull('suspended_until')
                  ->orWhere('suspended_until', '<=', Carbon::now());
            })
            ->distinct()
            ->count('corporation_id');
    }

    /**
     * Count enabled, non-suspended characters in the pool. Used by the
     * adaptive batch sizing + admin UI summary line.
     */
    public static function countEligible(): int
    {
        return (int) self::where('enabled', true)
            ->where(function ($q) {
                $q->whereNull('suspended_until')
                  ->orWhere('suspended_until', '<=', Carbon::now());
            })
            ->count();
    }

    /**
     * Get all eligible characters from SeAT that can be added to the pool.
     * These are characters with Director role + notifications ESI scope,
     * NOT already in the key pool.
     */
    public static function getEligibleCharacters(): \Illuminate\Support\Collection
    {
        return \DB::table('refresh_tokens as rt')
            ->join('character_affiliations as ca', 'rt.character_id', '=', 'ca.character_id')
            ->leftJoin('character_infos as ci', 'rt.character_id', '=', 'ci.character_id')
            ->leftJoin('corporation_infos as corp', 'ca.corporation_id', '=', 'corp.corporation_id')
            ->whereNull('rt.deleted_at')
            // Must have Director role
            ->whereExists(function ($query) {
                $query->select(\DB::raw(1))
                    ->from('corporation_roles')
                    ->whereColumn('corporation_roles.character_id', 'rt.character_id')
                    ->where('corporation_roles.type', 'roles')
                    ->where('corporation_roles.role', 'Director');
            })
            // Exclude characters already in the pool
            ->whereNotIn('rt.character_id', function ($query) {
                $query->select('character_id')
                    ->from('manager_core_esi_key_holders');
            })
            ->select(
                'rt.character_id',
                'ci.name as character_name',
                'ca.corporation_id',
                'corp.name as corporation_name',
                'rt.scopes',
                'rt.expires_on'
            )
            ->orderBy('corp.name')
            ->orderBy('ci.name')
            ->get()
            ->map(function ($row) {
                $scopes = $row->scopes;
                if (is_string($scopes)) {
                    $scopes = json_decode($scopes, true) ?? [];
                }
                $row->has_notification_scope = is_array($scopes)
                    && in_array('esi-characters.read_notifications.v1', $scopes);
                $row->token_expired = $row->expires_on
                    && Carbon::parse($row->expires_on)->lt(Carbon::now());
                return $row;
            });
    }

    /**
     * Record a successful poll. Clears all failure state including any
     * active suspension — operators don't have to do anything to recover
     * a character whose token spontaneously starts working again (which
     * happens often when SeAT's master refresh restores a token mid-day).
     */
    public function recordSuccess(int $notificationsFound = 0): void
    {
        $this->last_polled_at = Carbon::now();
        $this->last_poll_status = 'success';
        $this->last_error = null;
        $this->failure_category = null;
        $this->consecutive_failures = 0;
        $this->suspended_until = null;
        $this->total_polls++;
        $this->total_notifications_found += $notificationsFound;
        $this->save();
    }

    /**
     * Record a failed poll.
     *
     * @param string $status   Short status code (success/token_expired/
     *                         scope_missing/rate_limited/failed/etc.)
     * @param string $error    Human-readable error detail for the admin UI
     * @param string|null $category One of the CATEGORY_* constants. NULL
     *                              defaults to CATEGORY_UNKNOWN. Drives the
     *                              cooldown ladder.
     *
     * The cooldown is only set after 5 consecutive failures — earlier
     * failures get recorded but don't trigger suspension. This gives
     * transient single-poll blips (one timeout, one 401 right before a
     * background refresh) a chance to resolve naturally on the next poll
     * without admin intervention.
     */
    public function recordFailure(string $status, string $error, ?string $category = null): void
    {
        $this->last_polled_at = Carbon::now();
        $this->last_poll_status = $status;
        $this->last_error = $error;
        $this->failure_category = $category ?? self::CATEGORY_UNKNOWN;
        $this->consecutive_failures++;
        $this->total_polls++;

        if ($this->consecutive_failures >= 5) {
            $this->suspended_until = $this->computeCooldownEnd();
        }

        $this->save();
    }

    /**
     * Calculate when the suspension should expire given current state.
     *
     * Terminal categories (token deleted in SeAT, scope missing on the
     * token) park the character for 7 days — these states can't auto-
     * recover, so we don't want to waste error budget retrying every
     * hour for a token nobody's going to fix.
     *
     * Transient categories use exponential backoff starting at the 5th
     * consecutive failure: 30m, 1h, 2h, 4h, 8h, 16h, then 24h cap.
     *
     * Network failures (CCP infrastructure) get a tighter ladder since
     * they're usually short-lived: 10m, 20m, 40m, 80m, 160m, 320m, then
     * 24h cap.
     */
    protected function computeCooldownEnd(): Carbon
    {
        $category = $this->failure_category ?? self::CATEGORY_UNKNOWN;

        if (in_array($category, [self::CATEGORY_TERMINAL_AUTH, self::CATEGORY_SCOPE_MISSING], true)) {
            return Carbon::now()->addDays(7);
        }

        // Steps past the 5-strike threshold (0 on the 5th failure, 1 on the 6th, etc.)
        $overage = max(0, $this->consecutive_failures - 5);

        if ($category === self::CATEGORY_NETWORK) {
            // 10m starting, doubles each subsequent fail, 24h cap
            $minutes = min(10 * (2 ** $overage), 60 * 24);
        } else {
            // Default transient ladder: 30m, 1h, 2h, 4h, 8h, 16h, 24h cap
            $minutes = min(30 * (2 ** $overage), 60 * 24);
        }

        return Carbon::now()->addMinutes((int) $minutes);
    }

    /**
     * Manually clear the failure state. Called from the admin UI "Resume"
     * button. Differs from recordSuccess() in that it doesn't bump the
     * total_polls counter — this is a configuration action, not a poll.
     */
    public function resume(): void
    {
        $this->consecutive_failures = 0;
        $this->suspended_until = null;
        $this->last_poll_status = null;
        $this->last_error = null;
        $this->failure_category = null;
        $this->save();
    }

    /**
     * Check if this key holder's token has the required notification scope.
     */
    public function hasNotificationScope(): bool
    {
        $token = \Seat\Eveapi\Models\RefreshToken::find($this->character_id);
        if (!$token) {
            return false;
        }

        $scopes = $token->scopes ?? [];
        if (is_string($scopes)) {
            $scopes = json_decode($scopes, true) ?? [];
        }

        return in_array('esi-characters.read_notifications.v1', $scopes);
    }

    /**
     * Get a human-readable health status for display.
     *
     * Priority order:
     *   1. disabled — operator turned off the toggle
     *   2. suspended — cooldown is active right now (suspended_until > now)
     *   3. needs_attention — last failure was terminal (no token / no scope);
     *      cooldown might be active too, but the badge is the more useful
     *      signal because admin action is required
     *   4. degraded — has failures recorded but cooldown isn't active
     *   5. healthy — last poll succeeded with no recent failures
     *   6. unknown — never polled
     */
    public function getHealthStatus(): string
    {
        if (!$this->enabled) {
            return 'disabled';
        }
        if (in_array($this->failure_category, [self::CATEGORY_TERMINAL_AUTH, self::CATEGORY_SCOPE_MISSING], true)) {
            return 'needs_attention';
        }
        if ($this->suspended_until && $this->suspended_until->isFuture()) {
            return 'suspended';
        }
        if ($this->consecutive_failures > 0) {
            return 'degraded';
        }
        if ($this->last_poll_status === 'success') {
            return 'healthy';
        }
        return 'unknown';
    }

    /**
     * Human-friendly description of when the next retry will happen.
     * Returns null if no suspension is active. Used by the admin UI to
     * show "retries in 12h" or similar.
     */
    public function getRetryAvailableAtAttribute(): ?string
    {
        if (!$this->suspended_until || $this->suspended_until->isPast()) {
            return null;
        }
        return $this->suspended_until->diffForHumans();
    }

    /**
     * Get a CSS badge class for the health status.
     */
    public function getHealthBadgeClass(): string
    {
        $map = [
            'healthy' => 'badge-success',
            'degraded' => 'badge-warning',
            'needs_attention' => 'badge-danger',
            'suspended' => 'badge-dark',
            'disabled' => 'badge-secondary',
            'unknown' => 'badge-info',
        ];

        return $map[$this->getHealthStatus()] ?? 'badge-secondary';
    }
}
