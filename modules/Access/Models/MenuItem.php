<?php

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Model;

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

    protected $casts = [
        'parent_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
    protected function casts(): array
    {
        return [
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

    public static function visibleFor(?\App\Models\User $user, string $section = 'admin')
    {
        $query = self::query()->where('section', $section)->where('is_active', true)->orderBy('sort_order');

        $items = $query->get();
        if (!$user) {
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
