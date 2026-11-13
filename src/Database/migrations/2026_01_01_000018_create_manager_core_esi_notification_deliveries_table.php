<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// What became of each ESI notification at each plugin's handler: handled,
// queued, failed, or not sent because it was older than that handler takes.
// Manager Core keeps notifications up to two weeks old and each handler sets
// its own age, so one notification can reach one plugin and not another.
class CreateManagerCoreEsiNotificationDeliveriesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('manager_core_esi_notification_deliveries')) {
            Schema::create('manager_core_esi_notification_deliveries', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('notification_id')
                    ->comment('CCP notification ID, as in manager_core_esi_notifications');
                $table->string('handler', 191)
                    ->comment('Handler class the notification was for');
                $table->string('plugin', 100)
                    ->comment('Plugin that registered the handler');
                $table->string('status', 20)
                    ->comment('handled, queued, failed or too_old');
                $table->string('detail', 255)->nullable()
                    ->comment('Why it failed or was not sent');
                $table->timestamps();
            });
        }

        // Laravel adds a table's indexes after creating it, so a run that
        // stopped in between leaves the table without them.
        if (!$this->hasIndex('mc_esi_notif_deliv_unique')) {
            Schema::table('manager_core_esi_notification_deliveries', function (Blueprint $table) {
                $table->unique(['notification_id', 'handler'], 'mc_esi_notif_deliv_unique');
            });
        }

        if (!$this->hasIndex('mc_esi_notif_deliv_created_idx')) {
            Schema::table('manager_core_esi_notification_deliveries', function (Blueprint $table) {
                $table->index('created_at', 'mc_esi_notif_deliv_created_idx');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('manager_core_esi_notification_deliveries');
    }

    private function hasIndex(string $index): bool
    {
        try {
            return DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'manager_core_esi_notification_deliveries')
                ->where('INDEX_NAME', $index)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
