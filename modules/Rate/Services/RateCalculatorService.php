<?php

namespace Modules\Rate\Services;

use Modules\Rate\Models\MerchantRateCard;
use Modules\Rate\Models\RateRule;

class RateCalculatorService
{
    public function calculate(array $data, ?int $merchantId = null): array
    {
        $weight = max((float) ($data['weight'] ?? 1), 0.1);
        $codAmount = (float) ($data['pod_amount'] ?? 0);
        $originCity = strtolower((string) ($data['origin_city'] ?? $data['pickup_city'] ?? ''));
        $destinationCity = strtolower((string) ($data['destination_city'] ?? $data['delivery_city'] ?? $data['receiver_city'] ?? ''));

        $rateCardId = null;
        if ($merchantId) {
            $rateCardId = MerchantRateCard::where('merchant_id', $merchantId)->where('is_default', true)->value('rate_card_id');
        }

        $query = RateRule::query()->where('status', 'active')
            ->where('min_weight', '<=', $weight)
            ->where('max_weight', '>=', $weight);

        if ($rateCardId) {
            $query->where('rate_card_id', $rateCardId);
        }

        if ($originCity) {
            $query->where(function ($q) use ($originCity) {
                $q->whereNull('origin_city')->orWhereRaw('lower(origin_city) = ?', [$originCity]);
            });
        }

        if ($destinationCity) {
            $query->where(function ($q) use ($destinationCity) {
                $q->whereNull('destination_city')->orWhereRaw('lower(destination_city) = ?', [$destinationCity]);
            });
        }

        $rule = $query->orderByRaw('origin_city is null')
            ->orderByRaw('destination_city is null')
            ->orderBy('max_weight')
            ->first();

        if (!$rule) {
            $rule = RateRule::query()->where('status', 'active')->orderBy('id')->first();
        }

        $base = $rule ? (float) $rule->base_charge : 150;
        $extra = $rule ? max(0, $weight - (float) $rule->max_weight) * (float) $rule->extra_per_kg : 0;
        $codCharge = $rule ? ((float) $rule->pod_fixed + ($codAmount * ((float) $rule->pod_percent / 100))) : 0;
        $deliveryCharge = round($base + $extra, 2);

        return [
            'delivery_charge' => $deliveryCharge,
            'pod_charge' => round($codCharge, 2),
            'total_charge' => round($deliveryCharge + $codCharge, 2),
            'estimated_delivery_time' => $rule->estimated_delivery_time ?? '1-3 days',
            'rate_rule_id' => $rule->id ?? null,
        ];
    }
}
