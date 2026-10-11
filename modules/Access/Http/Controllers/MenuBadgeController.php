<?php

namespace Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Access\Services\MenuBadgeService;

/**
 * GET /api/v1/me/menu-badges?section=admin|staff
 *
 * Sidebar counts keyed by menu path, e.g.
 * { "badges": { "/admin/transfers": [ { key, count, label, color } ] } }
 * Not behind route.permission (like /me/menus); every badge checks the
 * permission of its own page.
 */
class MenuBadgeController extends Controller
{
    public function __construct(
        private readonly MenuBadgeService $badges,
    ) {}

    public function index(Request $request)
    {
        $section = (string) $request->query('section', 'admin');
        if (! in_array($section, ['admin', 'staff', 'merchant'], true)) {
            $section = 'admin';
        }

        return ApiResponse::success([
            'section' => $section,
            'badges' => (object) $this->badges->forUser($request->user(), $section),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
