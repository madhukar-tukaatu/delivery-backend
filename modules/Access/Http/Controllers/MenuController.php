<?php
namespace Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Access\Models\MenuItem;

class MenuController extends Controller
{
    /**
     * Get menus visible to the authenticated user.
     */
    public function my(Request $request)
    {
        $user = $request->user();

        $section = $request->get('section');

        if (! $section) {
            $section = $this->detectSection($user);
        }

        /*
        |--------------------------------------------------------------------------
        | Only allow known sections
        |--------------------------------------------------------------------------
        */

        if (! in_array($section, [
            'admin',
            'merchant',
            'staff',
        ], true)) {
            $section = 'admin';
        }

        /*
        |--------------------------------------------------------------------------
        | Load top-level menus
        |--------------------------------------------------------------------------
        */

        $menus = MenuItem::query()
            ->where('section', $section)
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->with([
                'children' => function ($query) {
                    $query
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Filter according to permissions
        |--------------------------------------------------------------------------
        */

        $menus = $menus
            ->map(function (MenuItem $menu) use ($user) {

                $children = $menu->children
                    ->filter(
                        fn(MenuItem $child) =>
                        $this->canSeeMenu($user, $child)
                    )
                    ->map(
                        fn(MenuItem $child) =>
                        $this->presentMenu($child)
                    )
                    ->values();

                $canSeeParent =
                $this->canSeeMenu($user, $menu);

                /*
                |--------------------------------------------------------------------------
                | Parent menu can still appear when it has visible children.
                |--------------------------------------------------------------------------
                */

                if (
                    ! $canSeeParent
                    && $children->isEmpty()
                ) {
                    return null;
                }

                $presented =
                $this->presentMenu($menu);

                $presented['children'] =
                $children->all();

                return $presented;
            })
            ->filter()
            ->values();

        return response()->json([
            'data' => $menus,
        ]);
    }

    /**
     * Admin menu CRUD.
     */
    public function index(Request $request)
    {
        $query = MenuItem::query()
            ->with([
                'parent:id,label,path,section',
            ])
            ->orderBy('section')
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($request->filled('section')) {
            $query->where(
                'section',
                $request->string('section')
            );
        }

        if ($request->filled('search')) {
            $search =
            $request->string('search');

            $query->where(function ($q) use ($search) {
                $q
                    ->where(
                        'label',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'path',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'permission',
                        'like',
                        "%{$search}%"
                    );
            });
        }

        return response()->json([
            'data' => $query->paginate(
                (int) $request->get(
                    'per_page',
                    50
                )
            ),
        ]);
    }

    /**
     * Create menu.
     */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        $menu = MenuItem::create($data);

        return response()->json([
            'message' =>
            'Menu created successfully.',

            'data'    => $menu->fresh('children'),
        ], 201);
    }

    /**
     * Update menu.
     */
    public function update(
        Request $request,
        MenuItem $menu
    ) {
        $data =
        $this->validated(
            $request,
            $menu->id
        );

        $menu->update($data);

        return response()->json([
            'message' =>
            'Menu updated successfully.',

            'data'    =>
            $menu->fresh('children'),
        ]);
    }

    /**
     * Delete menu.
     */
    public function destroy(MenuItem $menu)
    {
        /*
        |--------------------------------------------------------------------------
        | Prevent deleting a menu with children.
        |--------------------------------------------------------------------------
        */

        if ($menu->children()->exists()) {
            return response()->json([
                'message' =>
                'Cannot delete a menu that has child menus.',
            ], 422);
        }

        $menu->delete();

        return response()->json([
            'message' =>
            'Menu deleted successfully.',
        ]);
    }

    /**
     * Validation.
     */
    private function validated(
        Request $request,
        ?int $ignoreId = null
    ): array {
        return $request->validate([
            'parent_id'  => [
                'nullable',
                'integer',
                'exists:menu_items,id',
            ],

            'section'    => [
                'required',
                'string',
                Rule::in([
                    'admin',
                    'merchant',
                    'staff',
                ]),
            ],

            'label'      => [
                'required',
                'string',
                'max:255',
            ],

            'path'       => [
                'nullable',
                'string',
                'max:255',
            ],

            'icon'       => [
                'nullable',
                'string',
                'max:100',
            ],

            'permission' => [
                'nullable',
                'string',
                'max:255',
                'exists:permissions,name',
            ],

            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'is_active'  => [
                'nullable',
                'boolean',
            ],
        ]);
    }

    /**
     * Determine which portal the user belongs to.
     */
    private function detectSection($user): string
    {
        /*
        |--------------------------------------------------------------------------
        | Merchant
        |--------------------------------------------------------------------------
        */

        if (
            ! empty($user->merchant_id)
            || (
                method_exists($user, 'hasRole')
                && $user->hasRole([
                    'merchant',
                    'merchant_owner',
                    'merchant_admin',
                    'merchant_staff',
                ])
            )
        ) {
            return 'merchant';
        }

        /*
        |--------------------------------------------------------------------------
        | Operational staff
        |--------------------------------------------------------------------------
        */

        if (
            method_exists($user, 'hasRole')
            && $user->hasRole([
                'rider',
                'pickup_staff',
                'dispatch_staff',
                'delivery_staff',
                'delivery_rider',
                'warehouse_staff',
                'branch_staff',
                'support_staff',
                'accounts_staff',
            ])
        ) {
            return 'staff';
        }

        /*
        |--------------------------------------------------------------------------
        | Legacy role column fallback
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $user->role,
                [
                    'rider',
                    'pickup_staff',
                    'dispatch_staff',
                    'delivery_staff',
                    'delivery_rider',
                    'warehouse_staff',
                    'branch_staff',
                    'support_staff',
                    'accounts_staff',
                ],
                true
            )
        ) {
            return 'staff';
        }

        /*
        |--------------------------------------------------------------------------
        | Branch managers and administrators
        |--------------------------------------------------------------------------
        */

        return 'admin';
    }

    /**
     * Check menu visibility.
     */
    private function canSeeMenu(
        $user,
        MenuItem $menu
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | Super admin sees everything.
        |--------------------------------------------------------------------------
        */

        if (
            method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin()
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Menu without permission = public inside that portal.
        |--------------------------------------------------------------------------
        */

        if (
            empty($menu->permission)
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Spatie permission check.
        |--------------------------------------------------------------------------
        */

        return $user->can(
            $menu->permission
        );
    }

    /**
     * API representation.
     */
    private function presentMenu(
        MenuItem $menu
    ): array {
        return [
            'id'         => $menu->id,

            'key'        =>
            $menu->permission
                ?: (string) $menu->id,

            'label'      => $menu->label,

            'title'      => $menu->label,

            'path'       => $menu->path,

            'href'       => $menu->path,

            'route'      => $menu->path,

            'icon'       => $menu->icon,

            'permission' =>
            $menu->permission,

            'section'    =>
            $menu->section,

            'sort_order' =>
            $menu->sort_order,

            'is_active'  =>
            (bool) $menu->is_active,
        ];
    }
}
