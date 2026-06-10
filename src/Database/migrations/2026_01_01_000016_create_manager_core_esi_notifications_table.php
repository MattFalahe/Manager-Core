<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shared ESI notification cache (dedup + audit trail) for cross-plugin fast-poll.
//
// Manager Core polls ESI's notifications endpoint from the shared key holder pool
// and writes ALL structure-related notifications here, deduplicated by CCP's
// globally-unique notification_id. Plugins subscribe via EsiNotificationRegistry
// to receive callbacks for the notification types they care about.
class CreateManagerCoreEsiNotificationsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('manager_core_esi_notifications')) {
            Schema::create('manager_core_esi_notifications', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('notification_id')->unique()
                    ->comment('CCP globally unique notification ID — dedup key');
                $table->bigInteger('character_id')
                    ->comment('Director character we polled this from');
                $table->bigInteger('corporation_id')->index()
                    ->comment('Corporation this notification pertains to');
                $table->string('type', 100)->index()
                    ->comment('CCP notification type string (e.g. StructureUnderAttack)');
                $table->bigInteger('sender_id')->nullable()
                    ->comment('Entity that sent the notification');
                $table->string('sender_type', 50)->nullable()
                    ->comment('character, corporation, alliance, faction, other');
                $table->dateTime('timestamp')
                    ->comment('When CCP generated the notification');
                $table->text('text')->nullable()
                    ->comment('Raw YAML payload from CCP');
                $table->json('parsed_data')->nullable()
                    ->comment('Plugin-extracted key fields for quick access');
                $table->string('source', 20)->default('fast_poll')
                    ->comment('fast_poll or seat_fallback');
                $table->boolean('dispatched')->default(false)
                    ->comment('True after notification handlers have been invoked');
                $table->dateTime('dispatched_at')->nullable()
                    ->comment('When handlers were dispatched');
                $table->timestamps();

                $table->index(['corporation_id', 'type'], 'mc_esi_notif_corp_type_idx');
                $table->index(['dispatched', 'type'], 'mc_esi_notif_dispatch_idx');
                $table->index('timestamp', 'mc_esi_notif_timestamp_idx');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('manager_core_esi_notifications');
    }
}
