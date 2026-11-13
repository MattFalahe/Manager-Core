<?php

namespace ManagerCore\Models\ESI;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use ManagerCore\Models\Setting;

/**
 * Shared ESI notification cache for cross-plugin fast-poll.
 *
 * Populated by Manager Core's polling job (fast_poll source) and the
 * SeAT fallback sweep (seat_fallback source). Deduplicates by CCP's
 * globally-unique notification_id. Tracks whether registered handlers
 * have been invoked via the `dispatched` flag, set once at least one handler
 * got it; what became of it at each handler is in EsiNotificationDelivery.
 */
class EsiNotification extends Model
{
    protected $table = 'manager_core_esi_notifications';

    /** Holds when this install started keeping notifications up to two weeks old. */
    public const ARCHIVE_SINCE_KEY = 'esi.notification_archive_since';

    protected $fillable = [
        'notification_id',
        'character_id',
        'corporation_id',
        'type',
        'sender_id',
        'sender_type',
        'timestamp',
        'text',
        'parsed_data',
        'source',
        'dispatched',
        'dispatched_at',
    ];

    protected $casts = [
        'parsed_data' => 'array',
        'dispatched' => 'boolean',
    ];

    protected $dates = [
        'timestamp',
        'dispatched_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Mark this notification as dispatched to all registered handlers.
     */
    public function markDispatched(): void
    {
        $this->dispatched = true;
        $this->dispatched_at = Carbon::now();
        $this->save();
    }

    /**
     * Check if a notification_id has already been recorded.
     */
    public static function notificationExists(int $notificationId): bool
    {
        return self::where('notification_id', $notificationId)->exists();
    }

    /**
     * When this install started keeping notifications up to two weeks old, in
     * Unix seconds. Only notifications sent after it go out under the longer
     * ages, so an update never sends on what arrived before it. The first
     * call after the update records it.
     */
    public static function archiveSince(): int
    {
        $since = self::readArchiveSince();
        if ($since !== null) {
            return $since;
        }

        // Two jobs starting at once both get here; the first time written stays.
        DB::table('manager_core_settings')->insertOrIgnore([
            'key' => self::ARCHIVE_SINCE_KEY,
            'value' => json_encode(Carbon::now('UTC')->format('Y-m-d H:i:s')),
            'type' => 'esi',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return self::readArchiveSince() ?? Carbon::now()->getTimestamp();
    }

    private static function readArchiveSince(): ?int
    {
        $value = Setting::get(self::ARCHIVE_SINCE_KEY);
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC')->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Scope: undispatched notifications.
     */
    public function scopeUndispatched($query)
    {
        return $query->where('dispatched', false);
    }

    /**
     * Scope: notifications of specific types.
     */
    public function scopeOfTypes($query, array $types)
    {
        return $query->whereIn('type', $types);
    }

    /**
     * Scope: notifications from the fast-poll path.
     */
    public function scopeFromFastPoll($query)
    {
        return $query->where('source', 'fast_poll');
    }

    /**
     * Scope: notifications from the SeAT fallback path.
     */
    public function scopeFromSeatFallback($query)
    {
        return $query->where('source', 'seat_fallback');
    }
}
