<?php

namespace Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;

class AccessSyncController extends Controller
{
    public function sync(Request $request)
    {
        $user = $request->user();

        if (!method_exists($user, 'isSuperAdmin') || !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only super admin can sync permissions.',
            ], 403);
        }

        Artisan::call('app:sync-access');

        return response()->json([
            'success' => true,
            'message' => 'Permissions synced successfully.',
            'console_output' => Artisan::output(),
            'total_permissions' => Permission::where('guard_name', 'web')->count(),
        ]);
    }
}
