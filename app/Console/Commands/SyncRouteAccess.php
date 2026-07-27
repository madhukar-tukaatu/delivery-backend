<?php

namespace App\Console\Commands;

use App\Services\Access\RouteAccessSynchronizer;
use Illuminate\Console\Command;
use Throwable;

final class SyncRouteAccess extends Command
{
    protected $signature =
        'access:sync-routes';

    protected $description =
        'Create permissions and menus from registered application routes.';

    public function handle(
        RouteAccessSynchronizer $synchronizer
    ): int {
        try {
            $result =
                $synchronizer->sync();

            $this->info(
                sprintf(
                    'Route access synchronized: %d permissions and %d menus.',
                    $result['permissions'],
                    $result['menus']
                )
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);

            $this->error(
                $exception->getMessage()
            );

            return self::FAILURE;
        }
    }
}