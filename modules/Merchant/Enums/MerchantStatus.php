<?php

namespace Modules\Merchant\Enums;

enum MerchantStatus: string
{
    case Pending = 'pending';
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Inactive = 'inactive';

    public static function resolve(mixed $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return self::tryFrom($normalized) ?? self::Pending;
    }

    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases()
        );
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isApplicationQueue(): bool
    {
        return in_array($this, [self::Pending, self::PendingVerification], true);
    }
}