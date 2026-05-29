<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $table = 'manager_core_api_tokens';

    protected $fillable = [
        'user_id',
        'name',
        'token',
        'token_prefix',
        'hash_version',
        'scopes',
        'last_used_at',
        'last_used_ip',
        'expires_at',
        'is_active',
        'rate_limit',
        'metadata',
    ];

    protected $casts = [
        'scopes' => 'array',
        'is_active' => 'boolean',
        'rate_limit' => 'integer',
        'hash_version' => 'integer',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * L17: hash version markers.
     *  - 1 = legacy unsalted SHA-256 (kept for back-compat with existing tokens)
     *  - 2 = SHA-256 with APP_KEY-derived pepper (new default)
     */
    public const HASH_V1_UNSALTED = 1;
    public const HASH_V2_PEPPERED = 2;
    public const HASH_VERSION_DEFAULT = self::HASH_V2_PEPPERED;

    protected $hidden = ['token'];

    /**
     * All scopes the API supports.
     *
     * "Read" scopes are safe defaults — they only allow GET-style endpoints.
     * "Write" scopes (events.publish, appraisals.create) must be explicitly
     * granted to a token; null scopes give read-only access only.
     */
    public const SCOPE_PRICES_READ = 'prices.read';
    public const SCOPE_APPRAISALS_READ = 'appraisals.read';
    public const SCOPE_APPRAISALS_CREATE = 'appraisals.create';
    public const SCOPE_PLUGINS_READ = 'plugins.read';
    public const SCOPE_EVENTS_READ = 'events.read';
    public const SCOPE_EVENTS_PUBLISH = 'events.publish';

    public const ALL_SCOPES = [
        self::SCOPE_PRICES_READ,
        self::SCOPE_APPRAISALS_READ,
        self::SCOPE_APPRAISALS_CREATE,
        self::SCOPE_PLUGINS_READ,
        self::SCOPE_EVENTS_READ,
        self::SCOPE_EVENTS_PUBLISH,
    ];

    public const READ_ONLY_SCOPES = [
        self::SCOPE_PRICES_READ,
        self::SCOPE_APPRAISALS_READ,
        self::SCOPE_PLUGINS_READ,
        self::SCOPE_EVENTS_READ,
    ];

    public const WRITE_SCOPES = [
        self::SCOPE_APPRAISALS_CREATE,
        self::SCOPE_EVENTS_PUBLISH,
    ];

    /**
     * Generate a new API token
     *
     * Returns both the model and the raw token (only shown once).
     *
     * C2 fix: tokens default to READ_ONLY_SCOPES if no scopes are specified.
     * This prevents the previous "null = all scopes" privilege escalation.
     *
     * @param int $userId
     * @param string $name
     * @param array $options ['scopes' => [], 'expires_at' => Carbon, 'rate_limit' => int]
     * @return array ['token' => ApiToken, 'raw_token' => string]
     */
    public static function createToken(int $userId, string $name, array $options = []): array
    {
        $prefix = config('manager-core.api.token_prefix', 'mc_');
        $rawToken = $prefix . Str::random(48);

        // Default to read-only scopes if none specified
        $scopes = $options['scopes'] ?? null;
        if ($scopes === null) {
            $scopes = self::READ_ONLY_SCOPES;
        }

        // Validate scopes against allowed list
        $scopes = array_values(array_intersect($scopes, self::ALL_SCOPES));

        // L18: pre-fill metadata so the column has a documented schema
        $metadata = array_merge([
            'description' => $options['description'] ?? null,
            'created_via' => $options['created_via'] ?? 'web_ui',
            'created_user_agent' => request()?->userAgent(),
        ], (array) ($options['metadata'] ?? []));

        $token = static::create([
            'user_id' => $userId,
            'name' => $name,
            'token' => self::hashToken($rawToken, self::HASH_VERSION_DEFAULT),
            'hash_version' => self::HASH_VERSION_DEFAULT,
            'token_prefix' => substr($rawToken, 0, 8),
            'scopes' => $scopes,
            'expires_at' => $options['expires_at'] ?? null,
            'rate_limit' => $options['rate_limit'] ?? config('manager-core.api.default_rate_limit', 60),
            'is_active' => true,
            'metadata' => $metadata,
        ]);

        return [
            'token' => $token,
            'raw_token' => $rawToken,
        ];
    }

    /**
     * L17: Compute the stored hash for a raw token at a given version.
     *
     * V1 = unsalted SHA-256 (legacy)
     * V2 = SHA-256 of (raw . pepper) where pepper is derived from APP_KEY.
     *      Defends against DB-only leaks: an attacker with the tokens table
     *      but not APP_KEY can't brute-force the originals even with a
     *      rainbow table (which is already implausible at 48 random chars,
     *      but better-belt-and-braces).
     */
    public static function hashToken(string $rawToken, int $version): string
    {
        switch ($version) {
            case self::HASH_V2_PEPPERED:
                $pepper = self::pepper();
                return hash('sha256', $rawToken . '::' . $pepper);
            case self::HASH_V1_UNSALTED:
            default:
                return hash('sha256', $rawToken);
        }
    }

    /**
     * Derive a stable pepper from the application key. Doesn't change between
     * requests; rotates only if APP_KEY is rotated (in which case ALL tokens
     * are invalidated, which is the intended behaviour of an APP_KEY rotation).
     */
    protected static function pepper(): string
    {
        $appKey = config('app.key');
        // Strip the base64: prefix that Laravel uses
        if (is_string($appKey) && str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }
        return hash('sha256', 'mc_token_pepper::' . ($appKey ?? ''));
    }

    /**
     * Find a token by its raw value.
     *
     * L17: tries the peppered (V2) hash first, then falls back to legacy (V1)
     * for tokens issued before the pepper was added. Old tokens continue to
     * work — operators can rotate them at their leisure.
     */
    public static function findByToken(string $rawToken): ?self
    {
        // V2 (default for all new tokens) — peppered
        $v2 = static::where('token', self::hashToken($rawToken, self::HASH_V2_PEPPERED))->first();
        if ($v2) {
            return $v2;
        }

        // V1 fallback (legacy tokens issued before the pepper)
        return static::where('token', self::hashToken($rawToken, self::HASH_V1_UNSALTED))
            ->where('hash_version', self::HASH_V1_UNSALTED)
            ->first();
    }

    /**
     * L17 + L21: rotate this token in place. Generates a new raw token,
     * stores the peppered (V2) hash, and returns the new raw value.
     * Old hash + prefix overwritten — the old raw token is immediately invalid.
     */
    public function rotate(): string
    {
        $prefix = config('manager-core.api.token_prefix', 'mc_');
        $rawToken = $prefix . Str::random(48);

        $this->update([
            'token' => self::hashToken($rawToken, self::HASH_V2_PEPPERED),
            'hash_version' => self::HASH_V2_PEPPERED,
            'token_prefix' => substr($rawToken, 0, 8),
            // Reset usage data — different physical token now
            'last_used_at' => null,
            'last_used_ip' => null,
        ]);

        return $rawToken;
    }

    /**
     * Check if token has a specific scope
     *
     * C2 fix: Null scopes is no longer treated as "all scopes". Tokens with
     * scopes=null fail every scope check explicitly — they must have either
     * the explicit scope listed or be re-issued via createToken which now
     * defaults to READ_ONLY_SCOPES.
     *
     * @param string $scope
     * @return bool
     */
    public function hasScope(string $scope): bool
    {
        if ($this->scopes === null || !is_array($this->scopes)) {
            return false;
        }

        return in_array($scope, $this->scopes, true);
    }

    /**
     * Check if token has ANY of the given scopes (OR semantics)
     *
     * @param array $scopes
     * @return bool
     */
    public function hasAnyScope(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($this->hasScope($scope)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if token is valid (active and not expired)
     *
     * @return bool
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Record token usage — throttled to at most one DB write per minute.
     *
     * Avoids hammering the DB on high-frequency API calls (e.g. polling bots).
     *
     * @param string $ip
     * @return void
     */
    public function recordUsage(string $ip): void
    {
        $stale = $this->last_used_at === null
            || $this->last_used_at->diffInSeconds(now(), true) >= 60
            || $this->last_used_ip !== $ip;

        if (!$stale) {
            return;
        }

        $this->update([
            'last_used_at' => now(),
            'last_used_ip' => $ip,
        ]);
    }

    /**
     * Relationship to SeAT user
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\Seat\Web\Models\User::class, 'user_id');
    }
}
