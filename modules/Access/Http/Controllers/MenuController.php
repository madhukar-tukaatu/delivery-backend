<?php
namespace Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                'children' => function ($query) {
                    $query->orderBy('sort_order')->orderBy('id');
                },
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
     * Bulk reorder / re-parent menus for a section.
     *
     * Body: { section?: string, items: [{ id, parent_id, sort_order }, ...] }
     */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'section' => [
                'nullable',
                'string',
                Rule::in(['admin', 'merchant', 'staff']),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => [
                'required',
                'integer',
                'distinct',
                'exists:menu_items,id',
            ],
            'items.*.parent_id' => [
                'nullable',
                'integer',
                'exists:menu_items,id',
            ],
            'items.*.sort_order' => [
                'required',
                'integer',
                'min:0',
            ],
        ]);

        $items = collect($data['items']);
        $ids = $items->pluck('id')->all();
        $menus = MenuItem::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($menus->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'items' => 'One or more menu items were not found.',
            ]);
        }

        $section = $data['section'] ?? null;

        if ($section) {
            $mismatched = $menus->first(function (MenuItem $menu) use ($section) {
                $menuSection = $menu->section instanceof \BackedEnum
                    ? $menu->section->value
                    : (string) $menu->section;

                return $menuSection !== $section;
            });

            if ($mismatched) {
                throw ValidationException::withMessages([
                    'section' => 'All items must belong to the same section.',
                ]);
            }
        } else {
            $sections = $menus->map(function (MenuItem $menu) {
                return $menu->section instanceof \BackedEnum
                    ? $menu->section->value
                    : (string) $menu->section;
            })->unique()->values();

            if ($sections->count() > 1) {
                throw ValidationException::withMessages([
                    'items' => 'All items in a reorder request must belong to the same section.',
                ]);
            }

            $section = $sections->first();
        }

        // parent_id must reference an item in the same section (when provided)
        $parentIds = $items
            ->pluck('parent_id')
            ->filter()
            ->unique()
            ->values();

        if ($parentIds->isNotEmpty()) {
            $parents = MenuItem::query()
                ->whereIn('id', $parentIds->all())
                ->get()
                ->keyBy('id');

            foreach ($parentIds as $parentId) {
                $parent = $parents->get($parentId);

                if (! $parent) {
                    throw ValidationException::withMessages([
                        'items' => "Parent menu {$parentId} was not found.",
                    ]);
                }

                $parentSection = $parent->section instanceof \BackedEnum
                    ? $parent->section->value
                    : (string) $parent->section;

                if ($parentSection !== $section) {
                    throw ValidationException::withMessages([
                        'items' => 'Parent menu must belong to the same section.',
                    ]);
                }
            }
        }

        // Reject cycles: parent_id cannot be self or a descendant
        $proposedParent = [];
        foreach ($items as $row) {
            $proposedParent[(int) $row['id']] = isset($row['parent_id'])
                ? (int) $row['parent_id']
                : null;
        }

        foreach ($proposedParent as $id => $parentId) {
            if ($parentId === null) {
                continue;
            }

            if ($parentId === $id) {
                throw ValidationException::withMessages([
                    'items' => 'A menu cannot be its own parent.',
                ]);
            }

            if ($this->wouldCreateCycle($id, $parentId, $proposedParent, $menus)) {
                throw ValidationException::withMessages([
                    'items' => 'Reorder would create a circular parent relationship.',
                ]);
            }
        }

        $updated = 0;

        DB::transaction(function () use ($items, &$updated) {
            foreach ($items as $row) {
                $affected = MenuItem::query()
                    ->where('id', $row['id'])
                    ->update([
                        'parent_id' => $row['parent_id'] ?? null,
                        'sort_order' => $row['sort_order'],
                    ]);

                $updated += $affected;
            }
        });

        return response()->json([
            'message' => 'Menus reordered successfully.',
            'updated' => $updated,
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
     * Walk proposed / existing parents to detect a cycle.
     */
    private function wouldCreateCycle(
        int $itemId,
        int $newParentId,
        array $proposedParent,
        $menus
    ): bool {
        $visited = [];
        $current = $newParentId;

        while ($current !== null) {
            if ($current === $itemId) {
                return true;
            }

            if (isset($visited[$current])) {
                return true;
            }

            $visited[$current] = true;

            if (array_key_exists($current, $proposedParent)) {
                $current = $proposedParent[$current];
                continue;
            }

            $existing = $menus->get($current);

            if (! $existing) {
                $existing = MenuItem::query()->find($current);
            }

            $current = $existing?->parent_id !== null
                ? (int) $existing->parent_id
                : null;
        }

        return false;
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
