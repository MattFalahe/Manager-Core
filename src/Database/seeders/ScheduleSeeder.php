<?php

namespace ManagerCore\Database\Seeders;

use Illuminate\Database\Seeder;
use Seat\Services\Models\Schedule;

class ScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Update market prices every 4 hours
        Schedule::firstOrCreate([
            'command' => 'manager-core:update-prices',
        ], [
            'expression' => '0 */4 * * *', // Every 4 hours
            'allow_overlap' => false,
            'allow_maintenance' => false,
            'ping_before' => null,
            'ping_after' => null,
        ]);

        // Cleanup old data daily
        Schedule::firstOrCreate([
            'command' => 'manager-core:cleanup',
        ], [
            'expression' => '0 3 * * *', // Daily at 3 AM
            'allow_overlap' => false,
            'allow_maintenance' => false,
            'ping_before' => null,
            'ping_after' => null,
        ]);
    }
}
