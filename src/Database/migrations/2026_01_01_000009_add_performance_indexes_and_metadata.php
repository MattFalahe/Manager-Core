<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPerformanceIndexesAndMetadata extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add metadata field to plugin_registry if it doesn't exist
        if (Schema::hasTable('manager_core_plugin_registry')) {
            Schema::table('manager_core_plugin_registry', function (Blueprint $table) {
                if (!Schema::hasColumn('manager_core_plugin_registry', 'metadata')) {
                    $table->json('metadata')->nullable()->after('capabilities');
                }
            });
        }

        // Add composite index for appraisals filtering (user_id + created_at)
        if (Schema::hasTable('manager_core_appraisals')) {
            Schema::table('manager_core_appraisals', function (Blueprint $table) {
                // Check if index doesn't already exist
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexesFound = $sm->listTableIndexes('manager_core_appraisals');

                if (!isset($indexesFound['mc_appraisals_user_created_idx'])) {
                    $table->index(['user_id', 'created_at'], 'mc_appraisals_user_created_idx');
                }

                if (!isset($indexesFound['mc_appraisals_expires_idx'])) {
                    $table->index('expires_at', 'mc_appraisals_expires_idx');
                }
            });
        }

        // Add composite index for price_history cleanup queries
        if (Schema::hasTable('manager_core_price_history')) {
            Schema::table('manager_core_price_history', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexesFound = $sm->listTableIndexes('manager_core_price_history');

                if (!isset($indexesFound['mc_price_history_date_idx'])) {
                    $table->index(['date', 'market'], 'mc_price_history_date_idx');
                }
            });
        }

        // Add index for type_subscriptions market filtering
        if (Schema::hasTable('manager_core_type_subscriptions')) {
            Schema::table('manager_core_type_subscriptions', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexesFound = $sm->listTableIndexes('manager_core_type_subscriptions');

                if (!isset($indexesFound['mc_subs_type_market_idx'])) {
                    $table->index(['type_id', 'market'], 'mc_subs_type_market_idx');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Remove metadata field from plugin_registry
        if (Schema::hasTable('manager_core_plugin_registry')) {
            Schema::table('manager_core_plugin_registry', function (Blueprint $table) {
                if (Schema::hasColumn('manager_core_plugin_registry', 'metadata')) {
                    $table->dropColumn('metadata');
                }
            });
        }

        // Drop the added indexes
        if (Schema::hasTable('manager_core_appraisals')) {
            Schema::table('manager_core_appraisals', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexesFound = $sm->listTableIndexes('manager_core_appraisals');

                if (isset($indexesFound['mc_appraisals_user_created_idx'])) {
                    $table->dropIndex('mc_appraisals_user_created_idx');
                }

                if (isset($indexesFound['mc_appraisals_expires_idx'])) {
                    $table->dropIndex('mc_appraisals_expires_idx');
                }
            });
        }

        if (Schema::hasTable('manager_core_price_history')) {
            Schema::table('manager_core_price_history', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexesFound = $sm->listTableIndexes('manager_core_price_history');

                if (isset($indexesFound['mc_price_history_date_idx'])) {
                    $table->dropIndex('mc_price_history_date_idx');
                }
            });
        }

        if (Schema::hasTable('manager_core_type_subscriptions')) {
            Schema::table('manager_core_type_subscriptions', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexesFound = $sm->listTableIndexes('manager_core_type_subscriptions');

                if (isset($indexesFound['mc_subs_type_market_idx'])) {
                    $table->dropIndex('mc_subs_type_market_idx');
                }
            });
        }
    }
}
