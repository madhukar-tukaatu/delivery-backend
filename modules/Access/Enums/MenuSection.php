<?php

namespace Modules\Access\Enums;

enum MenuSection: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Merchant = 'merchant';

    public static function resolve(mixed $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return self::tryFrom($normalized) ?? self::Admin;
    }

    public static function values(): array
    {
        return array_map(
            static fn (self $section): string => $section->value,
            self::cases()
        );
    }
}