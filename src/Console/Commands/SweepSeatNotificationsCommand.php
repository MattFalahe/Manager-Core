<?php

namespace ManagerCore\Console\Commands;

use Illuminate\Console\Command;
use ManagerCore\Jobs\ESI\SweepSeatNotifications;

class SweepSeatNotificationsCommand extends Command
{
    protected $signature = 'manager-core:sweep-seat-notifications';

    protected $description = 'Fallback sweep of SeAT character_notifications table for any events missed by fast-poll';

    public function handle()
    {
        $this->info('Sweeping SeAT notification table for missed events...');
        dispatch(new SweepSeatNotifications());
        $this->info('Sweep job dispatched.');

        return 0;
    }
}
