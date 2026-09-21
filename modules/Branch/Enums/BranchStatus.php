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
}