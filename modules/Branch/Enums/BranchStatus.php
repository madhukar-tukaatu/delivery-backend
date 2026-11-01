<?php

namespace Modules\Branch\Enums;

enum BranchStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Rejected = 'rejected';
    case Closed = 'closed';

    public static function resolve(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return self::tryFrom($normalized) ?? self::Draft;
    }

    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases()
        );
    }

    public function isOperable(): bool
    {
        return $this === self::Active;
    }

    public function isApproved(): bool
    {
        return in_array($this, [self::Approved, self::Active], true);
    }
}