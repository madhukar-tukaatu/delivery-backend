<?php

namespace Modules\Access\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Access\Enums\MenuSection;

class MenuItem extends Model
{
    protected $guarded = [];

    protected $fillable = [
        'parent_id',
        'section',
        'label',
        'path',
        'icon',
        'permission',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'section' => MenuSection::class,
            'parent_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public static function visibleFor(?User $user, string|MenuSection $section = MenuSection::Admin)
    {
        $sectionValue = $section instanceof MenuSection
            ? $section->value
            : MenuSection::resolve($section)->value;

        $query = self::query()
            ->where('section', $sectionValue)
            ->where('is_active', true)
            ->orderBy('sort_order');

        $items = $query->get();
        if (! $user) {
            return collect();
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $items->values();
        }

        $permissions = collect(method_exists($user, 'permissionNames') ? $user->permissionNames() : []);

        return $items->filter(function ($item) use ($permissions) {
            return blank($item->permission) || $permissions->contains($item->permission);
        })->values();
    }
}