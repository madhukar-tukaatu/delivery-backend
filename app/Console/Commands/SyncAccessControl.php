<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

class SyncAccessControl extends Command
{
    protected $signature = 'app:sync-access';

    protected $description = 'Clear route cache, sync route permissions, refresh super admin, and clear permission cache';

    public function handle(): int
    {
        $this->info('Clearing cached routes/config first...');
        Artisan::call('optimize:clear');

        $this->info('Syncing permissions from named routes...');
        Artisan::call('app:sync-route-permissions');
        $this->line(Artisan::output());

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Access sync completed.');

        return self::SUCCESS;
    }
}
