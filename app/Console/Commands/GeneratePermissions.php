<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

class GeneratePermissions extends Command
{
    protected $signature = 'permissions:generate';
    protected $description = 'Generate permissions from route names';

    public function handle()
    {
        $routes = Route::getRoutes();

        $permissions = [];

        foreach ($routes as $route) {
            $name = $route->getName();

            if (!$name) {
                continue;
            }

            // Skip public routes
            if (str_starts_with($name, 'public.')) {
                continue;
            }

            // Example: admin.support-tickets.index
            $parts = explode('.', $name);

            if (count($parts) < 3) {
                continue;
            }

            $resource = $parts[1]; // support-tickets
            $action = $parts[2];   // index

            $permission = $this->mapActionToPermission($resource, $action);

            if ($permission) {
                $permissions[] = $permission;
            }
        }

        $permissions = array_unique($permissions);

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        $this->info('Permissions generated successfully!');
    }

    private function mapActionToPermission($resource, $action)
    {
        return match ($action) {
            'index', 'show' => "$resource.view",
            'store' => "$resource.create",
            'update' => "$resource.edit",
            'destroy' => "$resource.delete",
            default => null,
        };
    }
}