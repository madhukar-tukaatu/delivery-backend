<?php

namespace Modules\Rate\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Rate\Models\RateCard;
use Modules\Rate\Models\RateRule;
use Modules\Rate\Services\RateCalculatorService;

class RateController extends Controller
{
    public function cards(Request $request)
    {
        return ApiResponse::success(RateCard::withCount('rules')->latest()->paginate((int) $request->get('per_page', 20)));
    }

    public function storeCard(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'code' => ['required', 'string', 'unique:rate_cards,code'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        return ApiResponse::success(RateCard::create($data), 'Rate card created.', 201);
    }

    public function updateCard(Request $request, RateCard $card)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string'],
            'code' => ['sometimes', 'string', 'unique:rate_cards,code,'.$card->id],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        $card->update($data);
        return ApiResponse::success($card->fresh(), 'Rate card updated.');
    }

    public function deleteCard(RateCard $card)
    {
        $card->delete();
        return ApiResponse::success(null, 'Rate card deleted.');
    }

    public function rules(Request $request)
    {
        $query = RateRule::with('rateCard')->latest();
        if ($request->filled('rate_card_id')) $query->where('rate_card_id', $request->rate_card_id);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function storeRule(Request $request)
    {
        $data = $request->validate([
            'rate_card_id' => ['required', 'exists:rate_cards,id'],
            'origin_city' => ['nullable', 'string'],
            'destination_city' => ['nullable', 'string'],
            'min_weight' => ['required', 'numeric'],
            'max_weight' => ['required', 'numeric'],
            'base_charge' => ['required', 'numeric'],
            'extra_per_kg' => ['nullable', 'numeric'],
            'pod_percent' => ['nullable', 'numeric'],
            'pod_fixed' => ['nullable', 'numeric'],
            'return_charge' => ['nullable', 'numeric'],
            'estimated_delivery_time' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        return ApiResponse::success(RateRule::create($data), 'Rate rule created.', 201);
    }

    public function updateRule(Request $request, RateRule $rule)
    {
        $data = $request->validate([
            'rate_card_id' => ['sometimes', 'exists:rate_cards,id'],
            'origin_city' => ['nullable', 'string'],
            'destination_city' => ['nullable', 'string'],
            'min_weight' => ['sometimes', 'numeric'],
            'max_weight' => ['sometimes', 'numeric'],
            'base_charge' => ['sometimes', 'numeric'],
            'extra_per_kg' => ['nullable', 'numeric'],
            'pod_percent' => ['nullable', 'numeric'],
            'pod_fixed' => ['nullable', 'numeric'],
            'return_charge' => ['nullable', 'numeric'],
            'estimated_delivery_time' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        $rule->update($data);
        return ApiResponse::success($rule->fresh('rateCard'), 'Rate rule updated.');
    }

    public function deleteRule(RateRule $rule)
    {
        $rule->delete();
        return ApiResponse::success(null, 'Rate rule deleted.');
    }

    public function calculate(Request $request, RateCalculatorService $calculator)
    {
        $data = $request->validate([
            'origin_city' => ['nullable', 'string'],
            'destination_city' => ['nullable', 'string'],
            'pickup_city' => ['nullable', 'string'],
            'delivery_city' => ['nullable', 'string'],
            'weight' => ['required', 'numeric', 'min:0.1'],
            'pod_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $merchantId = $request->user()?->merchant_id;
        if (!$merchantId && $request->attributes->has('merchant')) {
            $merchantId = $request->attributes->get('merchant')->id;
        }
        return ApiResponse::success($calculator->calculate($data, $merchantId));
    }
}
