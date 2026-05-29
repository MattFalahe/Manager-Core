<?php

namespace ManagerCore\Models\ESI;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Shared ESI notification cache for cross-plugin fast-poll.
 *
 * Populated by Manager Core's polling job (fast_poll source) and the
 * SeAT fallback sweep (seat_fallback source). Deduplicates by CCP's
 * globally-unique notification_id. Tracks whether registered handlers
 * have been invoked via the `dispatched` flag.
 */
class EsiNotification extends Model
{
    protected $table = 'manager_core_esi_notifications';

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
