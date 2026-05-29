<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateManagerCoreEventSubscriptionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('manager_core_event_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('subscriber_plugin', 100);
            $table->string('event_pattern', 255);
            $table->string('handler_capability', 255);
            $table->string('handler_class', 255)->nullable();
            $table->string('handler_method', 100)->nullable()->default('handle');
            $table->boolean('is_queued')->default(false);
            $table->string('queue_name', 100)->nullable();
            $table->integer('priority')->default(0);
            // Per-subscription timeout — overrides the global EventBus default
            // when set. Used by subscribers that have especially long handlers
            // (e.g. fetching historical pricing). (folded from 000014)
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['subscriber_plugin', 'event_pattern', 'handler_capability'],
                'mc_event_sub_unique'
            );
            $table->index(['event_pattern', 'is_active'], 'mc_event_pattern_active');
            $table->index('subscriber_plugin', 'mc_event_subscriber');
            // Drives resolveSubscribers() — the existing (event_pattern, is_active)
            // index is wasteful for the actual query pattern. (folded from 000013)
            $table->index(['is_active', 'priority'], 'mc_event_active_priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_core_event_subscriptions');
    }
}
