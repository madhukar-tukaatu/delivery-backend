<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Branch\Models\Branch;
use Modules\Branch\Models\BranchTeamPosition;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Merchant\Models\Merchant;
use Modules\Pickup\Models\PickupRequest;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Guard;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;

    protected string $guard_name = 'web';

    public const ACCOUNT_ACTIVE = 'active';

    public const ACCOUNT_SUSPENDED = 'suspended';

    public const ACCOUNT_DISABLED = 'disabled';

    protected static function booted(): void
    {
        static::saved(function (User $user) {
            $role = trim((string) $user->role);
            if ($role === '') {
                return;
            }

            $currentRoles = $user
                ->getRoleNames()
                ->values()
                ->all();

            if ($currentRoles === [$role]) {
                return;
            }

            // The users.role column can hold a legacy value (old DB default
            // "staff") that is not a Spatie role. Do not fail the save and do
            // not wipe the user's real roles in that case.
            $guard = Guard::getDefaultName($user);
            try {
                app(PermissionRegistrar::class)->getRoleClass()::findByName($role, $guard);
            } catch (RoleDoesNotExist) {
                Log::warning('users.role does not match an existing role; Spatie roles left unchanged.', [
                    'user_id' => $user->id,
                    'role' => $role,
                    'guard' => $guard,
                ]);

                return;
            }

            $user->syncRoles([$role]);

            app(PermissionRegistrar::class)
                ->forgetCachedPermissions();
        });
    }

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'assigned_at' => 'datetime',
            'account_setup_completed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function teamPosition(): HasOne
    {
        return $this->hasOne(
            BranchTeamPosition::class,
            'user_id'
        );
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function hasAnyLegacyRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function roleNames(): array
    {
        try {
            $roles = $this
                ->getRoleNames()
                ->values()
                ->all();

            return count($roles)
                ? $roles
                : array_filter([$this->role]);
        } catch (\Throwable $e) {
            return array_filter([$this->role]);
        }
    }

    public function permissionNames(): array
    {
        try {
            if (
                $this->hasRole('super_admin') ||
                $this->role === 'super_admin'
            ) {
                return \Spatie\Permission\Models\Permission::query()
                    ->pluck('name')
                    ->values()
                    ->all();
            }

            return $this
                ->getAllPermissions()
                ->pluck('name')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function isSuperAdmin(): bool
    {
        try {
            return $this->role === 'super_admin'
                || $this->hasRole('super_admin');
        } catch (\Throwable $e) {
            return $this->role === 'super_admin';
        }
    }

    public function assignedPickups(): HasMany
    {
        return $this->hasMany(
            PickupRequest::class,
            'assigned_to'
        );
    }

    public function assignedDeliveries(): HasMany
    {
        return $this->hasMany(
            DeliveryAssignment::class,
            'rider_id'
        );
    }
}
