<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

class SyncAccessControl extends Command
{
    protected $signature =
        'app:sync-access';

    protected $description =
        'Clear application cache and synchronize route permissions.';

    public function handle(): int
    {
        /*
        |--------------------------------------------------------------------------
        | Clear application cache
        |--------------------------------------------------------------------------
        */

        $this->info(
            'Clearing cached routes/config...'
        );

        Artisan::call(
            'optimize:clear'
        );

        $this->line(
            Artisan::output()
        );

        /*
        |--------------------------------------------------------------------------
        | Sync route permissions
        |--------------------------------------------------------------------------
        */

        $this->info(
            'Synchronizing route permissions...'
        );

        $exitCode =
            Artisan::call(
                'access:sync-routes'
            );

        $this->line(
            Artisan::output()
        );

        if ($exitCode !== Command::SUCCESS) {
            $this->error(
                'Route access synchronization failed.'
            );

            return self::FAILURE;
        }

        /*
        |--------------------------------------------------------------------------
        | Clear Spatie permission cache
        |--------------------------------------------------------------------------
        */

        app(
            PermissionRegistrar::class
        )->forgetCachedPermissions();

        $this->info(
            'Permission cache cleared.'
        );

        $this->newLine();

        $this->info(
            'Access control synchronization completed.'
        );

        return self::SUCCESS;
    }
}