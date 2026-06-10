<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ESI key holder pool for fast structure notification polling (shared across plugins).
//
// Admins assign director characters to this pool. The Manager Core polling job
// round-robins through enabled key holders, skipping any with expired tokens or
// recent failures. The more characters in the pool, the faster detection AND the
// more fault-tolerant the system. Used by any plugin that registers a handler
// with EsiNotificationRegistry.
class CreateManagerCoreEsiKeyHoldersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('manager_core_esi_key_holders')) {
            Schema::create('manager_core_esi_key_holders', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('character_id')->unique()
                    ->comment('SeAT character_id (matches refresh_tokens PK)');
                $table->bigInteger('corporation_id')->index()
                    ->comment('Corporation the character belongs to');
                $table->string('character_name', 100)->nullable()
                    ->comment('Cached name for display (from character_infos)');
                $table->boolean('enabled')->default(true)
                    ->comment('Admin toggle: include in polling rotation');

                // Polling state (managed by the job, not admin)
                $table->dateTime('last_polled_at')->nullable()
                    ->comment('When this character was last polled');
                // Folded from 000019 (consolidated pre-v1.0.0 release): widened
                // from VARCHAR(20) to VARCHAR(40) to fit the auto-recovery
                // status strings (token_invalid_transient = 23 chars,
                // token_invalid_permanent = 23 chars) introduced when the
                // failure_category column landed.
                $table->string('last_poll_status', 40)->nullable()
                    ->comment('success | failed | token_expired | scope_missing | rate_limited | token_invalid_transient | token_invalid_permanent');
                // Folded from 000018 (consolidated pre-v1.0.0 release): coarse
                // classification of WHY the last failure happened so the
                // cooldown length can be tuned (transient_auth / terminal_auth
                // / scope_missing / rate_limited / network / unknown).
                $table->string('failure_category', 30)->nullable()
                    ->comment('Coarse classification: transient_auth, terminal_auth, scope_missing, rate_limited, network, unknown.');
                $table->text('last_error')->nullable()
                    ->comment('Error message from last failed poll');
                $table->integer('consecutive_failures')->default(0)
                    ->comment('Failures in a row — used for backoff');
                // Folded from 000018: when the suspension cooldown ends. NULL =
                // not suspended. The rotation query checks `suspended_until IS
                // NULL OR suspended_until <= NOW()`, so an expired cooldown
                // returns the character to rotation naturally. Replaces the
                // pre-fix 'consecutive_failures >= 5 = permanent suspension'
                // deadlock that left directors stuck forever.
                $table->timestamp('suspended_until')->nullable()
                    ->comment('NULL = not suspended. When set, rotation skips this row until the timestamp passes.');
                $table->integer('total_polls')->default(0)
                    ->comment('Lifetime poll count for this character');
                $table->integer('total_notifications_found')->default(0)
                    ->comment('Lifetime new notifications discovered via this character');

                $table->timestamps();

                // Folded from 000018: replaces the original (enabled,
                // last_polled_at) idx — auto-recovery rotation queries filter
                // by suspended_until too, so a wider composite index serves
                // both the legacy + recovery-aware patterns.
                $table->index(['enabled', 'suspended_until', 'last_polled_at'], 'mc_key_holders_rotation_v2_idx');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('manager_core_esi_key_holders');
    }
}
