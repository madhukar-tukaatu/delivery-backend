<?php

namespace Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Access\Models\MenuItem;

class MenuController extends Controller
{
    /**
     * Dynamic sidebar/menu endpoint for logged-in user.
     *
     * Important:
     * - Super admin sees all active menus in the requested section.
     * - Other users only see menus where user can(menu.permission).
     * - Empty permission means visible to all authenticated users in that section.
     */
    // public function my(Request $request)
    // {
    //     $user = $request->user();
    //     $section = $request->get('section') ?: $this->detectSection($user);

    //     $menus = MenuItem::query()
    //         ->where('section', $section)
    //         ->where('is_active', true)
    //         ->whereNull('parent_id')
    //         ->with(['children' => function ($query) {
    //             $query->where('is_active', true)->orderBy('sort_order');
    //         }])
    //         ->orderBy('sort_order')
    //         ->get()
    //         ->filter(fn ($menu) => $this->canSeeMenu($user, $menu))
    //         ->map(function ($menu) use ($user) {
    //             $menu->children = $menu->children
    //                 ->filter(fn ($child) => $this->canSeeMenu($user, $child))
    //                 ->values();

    //             return $menu;
    //         })
    //         ->values();

    //     return response()->json([
    //         'data' => $menus,
    //     ]);
    // }

    public function my(Request $request)
    {
        $user = $request->user();

        $section = $request->get('section', 'admin');

        $menus = \Modules\Access\Models\MenuItem::query()
            ->where('section', $section)
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->with(['children' => function ($query) {
                $query->where('is_active', true)
                    ->orderBy('sort_order');
            }])
            ->orderBy('sort_order')
            ->get()
            ->filter(function ($menu) use ($user) {
                if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                    return true;
                }

                if (empty($menu->permission)) {
                    return true;
                }

                return $user->can($menu->permission);
            })
            ->map(function ($menu) use ($user) {
                $menu->children = $menu->children
                    ->filter(function ($child) use ($user) {
                        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                            return true;
                        }

                        if (empty($child->permission)) {
                            return true;
                        }

                        return $user->can($child->permission);
                    })
                    ->values();

                return $menu;
            })
            ->values();

        return response()->json([
            'data' => $menus,
        ]);
    }
    /**
     * Admin menu CRUD list.
     */
    public function index(Request $request)
    {
        $query = MenuItem::query()
            ->with('parent:id,label,path,section')
            ->orderBy('section')
            ->orderBy('sort_order');

        if ($request->filled('section')) {
            $query->where('section', $request->string('section'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('label', 'like', "%{$search}%")
                    ->orWhere('path', 'like', "%{$search}%")
                    ->orWhere('permission', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->paginate((int) $request->get('per_page', 50)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $menu = MenuItem::create($data);

        return response()->json([
            'message' => 'Menu created successfully.',
            'data' => $menu,
        ], 201);
    }

    public function update(Request $request, MenuItem $menu)
    {
        $data = $this->validated($request, $menu->id);

        $menu->update($data);

        return response()->json([
            'message' => 'Menu updated successfully.',
            'data' => $menu->fresh('children'),
        ]);
    }

    public function destroy(MenuItem $menu)
    {
        $menu->delete();

        return response()->json([
            'message' => 'Menu deleted successfully.',
        ]);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:menu_items,id'],
            'section' => ['required', 'string', Rule::in(['admin', 'merchant', 'staff'])],
            'label' => ['required', 'string', 'max:255'],
            'path' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'permission' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function detectSection($user): string
    {
        if (method_exists($user, 'hasRole') && $user->hasRole('merchant')) {
            return 'merchant';
        }

        if (method_exists($user, 'hasRole') && ($user->hasRole('pickup_staff') || $user->hasRole('delivery_rider'))) {
            return 'staff';
        }

        return 'admin';
    }

    private function canSeeMenu($user, MenuItem $menu): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (empty($menu->permission)) {
            return true;
        }

        return $user->can($menu->permission);
    }
}
