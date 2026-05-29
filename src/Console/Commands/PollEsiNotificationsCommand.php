<?php

namespace ManagerCore\Console\Commands;

use Illuminate\Console\Command;
use ManagerCore\Jobs\ESI\PollEsiNotifications;

class PollEsiNotificationsCommand extends Command
{
    protected $signature = 'manager-core:poll-esi-notifications';

    protected $description = 'Fast-poll ESI notifications from the shared key holder pool (serves all plugins that register handlers)';

    public function handle()
    {
        $this->info('Polling ESI notifications from shared key holder pool...');
        dispatch(new PollEsiNotifications());
        $this->info('ESI polling job dispatched.');

        return 0;
    }
}
