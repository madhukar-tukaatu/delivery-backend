<?php

namespace Modules\Access\Enums;

enum SystemRole: string
{
    case SuperAdmin = 'super_admin';
    case MainAdmin = 'main_admin';
    case BranchManager = 'branch_manager';
    case SubBranchManager = 'sub_branch_manager';
    case BookingStaff = 'booking_staff';
    case PickupStaff = 'pickup_staff';
    case DispatchStaff = 'dispatch_staff';
    case DeliveryStaff = 'delivery_staff';
    case WarehouseStaff = 'warehouse_staff';
    case Rider = 'rider';
    case AccountsStaff = 'accounts_staff';
    case SupportStaff = 'support_staff';
    case BranchStaff = 'branch_staff';
    case Merchant = 'merchant';

    public static function resolve(mixed $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return self::tryFrom($normalized) ?? self::BranchStaff;
    }

    public static function values(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            self::cases()
        );
    }
}