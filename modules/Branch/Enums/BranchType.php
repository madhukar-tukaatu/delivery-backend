<?php

namespace Modules\Branch\Enums;

enum BranchType: string
{
    case HeadBranch = 'head_branch';
    case FranchiseBranch = 'franchise_branch';
    case SubBranch = 'sub_branch';
    case PickupPoint = 'pickup_point';
    case DeliveryHub = 'delivery_hub';

    public static function resolve(mixed $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return self::tryFrom($normalized)
            ?? match ($normalized) {
                'main_branch' => self::HeadBranch,
                'branch' => self::FranchiseBranch,
                default => self::HeadBranch,
            };
    }

    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases()
        );
    }

    public function isMainBranch(): bool
    {
        return in_array($this, [self::HeadBranch, self::FranchiseBranch], true);
    }

    public function isSubBranch(): bool
    {
        return $this === self::SubBranch;
    }
}