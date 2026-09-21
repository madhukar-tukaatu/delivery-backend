<?php

namespace Modules\Branch\Enums;

enum ActiveInactive: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public static function resolve(mixed $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return self::tryFrom($normalized) ?? self::Active;
    }

    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases()
        );
    }

    public static function fromBool(bool $active): self
    {
        return $active ? self::Active : self::Inactive;
    }
}