<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

class GeneratePermissionsFromRoutes extends Command
{
    protected $signature = 'permissions:generate-from-routes';
    protected $description = 'Generate permissions automatically from route names';

    public function handle()
    {
        $this->info('Generating permissions from routes...');

        $routes = Route::getRoutes();
        $generated = 0;

        foreach ($routes as $route) {

            $uri = $route->uri();

            // ✅ Only target admin routes (adjust if needed)
            if (!str_starts_with($uri, 'api/v1/admin')) {
                continue;
            }

            // remove prefix
            $clean = str_replace('api/v1/admin/', '', $uri);

            // skip empty or root
            if (!$clean) continue;

            // remove parameters {id}
            if (str_contains($clean, '{')) {
                $clean = explode('/{', $clean)[0];
            }

            $segments = explode('/', $clean);
            $resource = $segments[0] ?? null;

            if (!$resource) continue;

            // detect action from HTTP method
            $methods = $route->methods();

            $action = match (true) {
                in_array('GET', $methods) && count($segments) === 1 => 'view',
                in_array('POST', $methods) => 'create',
                in_array('PUT', $methods) || in_array('PATCH', $methods) => 'edit',
                in_array('DELETE', $methods) => 'delete',
                default => 'view',
            };

            $permissionName = "{$resource}.{$action}";

            if (!Permission::where('name', $permissionName)->exists()) {
                Permission::create(['name' => $permissionName]);
                $this->line("✓ Created: <fg=green>{$permissionName}</>");
                $generated++;
            }
        }

        $this->info("✅ Done! {$generated} new permissions generated.");
    }
}
