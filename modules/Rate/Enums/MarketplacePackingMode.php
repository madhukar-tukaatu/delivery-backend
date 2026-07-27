<?php

namespace Modules\Rate\Enums;

enum MarketplacePackingMode: string
{
    case SinglePerStore =
        'single_per_store';

    case PerProductQuantity =
        'per_product_quantity';

    case ExplicitPackets =
        'explicit_packets';

    public static function configured(): self
    {
        return self::resolve(
            config(
                'marketplace.store_packet_mode',
                self::SinglePerStore->value
            )
        );
    }

    public static function resolve(
        mixed $value
    ): self {
        $normalized = strtolower(
            trim((string) $value)
        );

        return self::tryFrom($normalized)
            ?? self::SinglePerStore;
    }

    public static function values(): array
    {
        return array_map(
            static fn (self $mode): string =>
                $mode->value,
            self::cases()
        );
    }

    public function isSinglePerStore(): bool
    {
        return $this ===
            self::SinglePerStore;
    }

    public function isPerProductQuantity(): bool
    {
        return $this ===
            self::PerProductQuantity;
    }

    public function isExplicitPackets(): bool
    {
        return $this ===
            self::ExplicitPackets;
    }
}