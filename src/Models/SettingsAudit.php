<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * L14: append-only audit trail of settings changes.
 *
 * Every Setting::set() that comes from the SettingsController is mirrored
 * here with the user, IP, old value and new value. Provides the missing
 * "who changed what when" for the Settings UI.
 */
class SettingsAudit extends Model
{
    protected $table = 'manager_core_settings_audit';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_name',
        'setting_key',
        'old_value',
        'new_value',
        'source',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Convenience writer used by SettingsController::save() and similar.
     * Skips the row if old and new values are byte-equal (no real change).
     */
    public static function record(string $key, $oldValue, $newValue, string $source = 'settings_ui'): ?self
    {
        $oldStr = is_scalar($oldValue) || $oldValue === null ? (string) $oldValue : json_encode($oldValue);
        $newStr = is_scalar($newValue) || $newValue === null ? (string) $newValue : json_encode($newValue);

        if ($oldStr === $newStr) {
            return null;
        }

        try {
            $user = auth()->user();

            return static::create([
                'user_id' => $user->id ?? null,
                'user_name' => $user->name ?? null,
                'setting_key' => substr($key, 0, 150),
                'old_value' => $oldStr,
                'new_value' => $newStr,
                'source' => $source,
                'ip' => request()?->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Manager Core] Settings audit write failed: ' . $e->getMessage());
            return null;
        }
    }
}
