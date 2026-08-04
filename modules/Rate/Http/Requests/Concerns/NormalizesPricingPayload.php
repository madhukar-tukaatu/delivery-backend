<?php

namespace Modules\Rate\Http\Requests\Concerns;

trait NormalizesPricingPayload
{
    /**
     * Normalize one store/shipment pricing input and derive
     * aggregate compatibility fields.
     */
    protected function normalizePricingShipment(
        array $input
    ): array {
        $products = is_array($input['products'] ?? null)
            ? array_values($input['products'])
            : [];

        $packets = is_array($input['packets'] ?? null)
            ? array_values($input['packets'])
            : [];

        $hasProducts = count($products) > 0;
        $hasPackets = count($packets) > 0;

        if ($hasProducts && $hasPackets) {
            $inputMode = 'mixed';
        } elseif ($hasProducts) {
            $inputMode = 'products';
        } elseif ($hasPackets) {
            $inputMode = 'packets';
        } elseif (
            array_key_exists('parcel_weight', $input) &&
            $input['parcel_weight'] !== null &&
            $input['parcel_weight'] !== ''
        ) {
            $inputMode = 'legacy_single_parcel';
        } else {
            $inputMode = 'missing';
        }

        $parcelWeight = $input['parcel_weight'] ?? null;
        $parcelValue = $input['parcel_value'] ?? null;
        $parcelType = $this->normalizeParcelType(
            $input['parcel_type'] ?? 'non_fragile'
        );

        $packetCount = max(
            1,
            (int) ($input['packet_count'] ?? 1)
        );

        /*
         * Products:
         * Every product quantity unit becomes one physical packet.
         */
        if ($hasProducts) {
            $calculatedWeight = 0.0;
            $calculatedValue = 0.0;
            $calculatedPacketCount = 0;
            $containsFragile = false;

            $products = array_map(
                function (array $product) use (
                    &$calculatedWeight,
                    &$calculatedValue,
                    &$calculatedPacketCount,
                    &$containsFragile
                ): array {
                    $quantity = max(
                        0,
                        (int) ($product['quantity'] ?? 0)
                    );

                    $unitWeight = max(
                        0,
                        (float) ($product['unit_weight'] ?? 0)
                    );

                    $unitPrice = max(
                        0,
                        (float) ($product['unit_price'] ?? 0)
                    );

                    $productType = $this->normalizeParcelType(
                        $product['parcel_type']
                            ?? 'non_fragile'
                    );

                    $calculatedPacketCount += $quantity;

                    $calculatedWeight +=
                        $quantity * $unitWeight;

                    $calculatedValue +=
                        $quantity * $unitPrice;

                    if ($productType === 'fragile') {
                        $containsFragile = true;
                    }

                    return [
                        ...$product,

                        'parcel_type' =>
                            $productType,
                    ];
                },
                $products
            );

            $parcelWeight = round(
                $calculatedWeight,
                3
            );

            $parcelValue = round(
                $calculatedValue,
                2
            );

            $parcelType = $containsFragile
                ? 'fragile'
                : 'non_fragile';

            $packetCount = max(
                1,
                $calculatedPacketCount
            );
        }

        /*
         * Packets:
         * Every entry represents one physical packet.
         */
        if ($hasPackets) {
            $calculatedWeight = 0.0;
            $calculatedValue = 0.0;
            $containsFragile = false;

            $packets = array_map(
                function (array $packet) use (
                    &$calculatedWeight,
                    &$calculatedValue,
                    &$containsFragile
                ): array {
                    $actualWeight = max(
                        0,
                        (float) (
                            $packet['actual_weight']
                            ?? 0
                        )
                    );

                    $declaredValue = max(
                        0,
                        (float) (
                            $packet['declared_value']
                            ?? $packet['unit_price']
                            ?? 0
                        )
                    );

                    $packetType = $this->normalizeParcelType(
                        $packet['parcel_type']
                            ?? 'non_fragile'
                    );

                    $calculatedWeight +=
                        $actualWeight;

                    $calculatedValue +=
                        $declaredValue;

                    if ($packetType === 'fragile') {
                        $containsFragile = true;
                    }

                    return [
                        ...$packet,

                        'quantity' => 1,

                        'declared_value' =>
                            $declaredValue,

                        'parcel_type' =>
                            $packetType,
                    ];
                },
                $packets
            );

            $parcelWeight = round(
                $calculatedWeight,
                3
            );

            $parcelValue = round(
                $calculatedValue,
                2
            );

            $parcelType = $containsFragile
                ? 'fragile'
                : 'non_fragile';

            $packetCount = count($packets);
        }

        return [
            ...$input,

            'products' =>
                $products,

            'packets' =>
                $packets,

            'pricing_input_mode' =>
                $inputMode,

            'parcel_weight' =>
                $parcelWeight,

            'parcel_value' =>
                $parcelValue,

            'parcel_type' =>
                $parcelType,

            'packet_count' =>
                $packetCount,

            'payment_type' =>
                $this->normalizePaymentType(
                    $input['payment_type']
                        ?? 'prepaid'
                ),

            'service_type' =>
                $this->normalizeServiceType(
                    $input['service_type']
                        ?? 'standard'
                ),
        ];
    }

    protected function normalizeParcelType(
        mixed $value
    ): string {
        $value = strtolower(
            trim((string) $value)
        );

        return match ($value) {
            'fragile' => 'fragile',

            'non-fragile',
            'non fragile',
            'normal',
            'regular' => 'non_fragile',

            default => $value,
        };
    }

    protected function normalizePaymentType(
        mixed $value
    ): string {
        $value = strtolower(
            trim((string) $value)
        );

        return match ($value) {
            'pod',
            'payment_on_delivery',
            'cash_on_delivery',
            'cash-on-delivery',
            'payment-on-delivery' => 'pod',

            default => $value,
        };
    }

    protected function normalizeServiceType(
        mixed $value
    ): string {
        $value = strtolower(
            trim((string) $value)
        );

        return match ($value) {
            'same-day',
            'same day',
            'sameday' => 'same_day',

            default => $value,
        };
    }
}