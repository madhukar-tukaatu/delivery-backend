<?php

declare(strict_types=1);

namespace Modules\Rate\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Merchant\Services\MerchantApiKeyGuard;
use Modules\Rate\Services\PricingEngineService;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;


class PublicPricingQuoteController extends Controller
{
    #[OA\Post(
        path: '/api/v1/public-merchant/pricing/quote',
        operationId: 'publicMerchantPricingQuote',
        summary: 'Calculate a delivery price quote',
        description: 'Calculates the available delivery price quote for a public merchant request using address coordinates.',
        tags: ['Public Merchant Pricing'],
        security: [['TukaatuApiKey' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: [
                    'pickup_latitude',
                    'pickup_longitude',
                    'delivery_latitude',
                    'delivery_longitude',
                    'parcel_weight',
                    'payment_type',
                    'service_type'
                ],
                properties: [
                    new OA\Property(
                        property: 'pickup_address',
                        type: 'string',
                        maxLength: 255,
                        nullable: true,
                        example: '123 Main Street, Kathmandu'
                    ),
                    new OA\Property(
                        property: 'pickup_latitude',
                        type: 'number',
                        format: 'float',
                        example: 27.7172
                    ),
                    new OA\Property(
                        property: 'pickup_longitude',
                        type: 'number',
                        format: 'float',
                        example: 85.3240
                    ),
                    new OA\Property(
                        property: 'delivery_address',
                        type: 'string',
                        maxLength: 255,
                        nullable: true,
                        example: '456 Park Avenue, Lalitpur'
                    ),
                    new OA\Property(
                        property: 'delivery_latitude',
                        type: 'number',
                        format: 'float',
                        example: 27.6722
                    ),
                    new OA\Property(
                        property: 'delivery_longitude',
                        type: 'number',
                        format: 'float',
                        example: 85.3240
                    ),
                    new OA\Property(
                        property: 'parcel_weight',
                        type: 'number',
                        format: 'float',
                        minimum: 0.01,
                        example: 2.5
                    ),
                    new OA\Property(
                        property: 'parcel_value',
                        type: 'number',
                        format: 'float',
                        minimum: 0,
                        nullable: true,
                        example: 1500.00
                    ),
                    new OA\Property(
                        property: 'payment_type',
                        type: 'string',
                        enum: ['cod', 'prepaid'],
                        example: 'cod'
                    ),
                    new OA\Property(
                        property: 'cod_amount',
                        type: 'number',
                        format: 'float',
                        minimum: 0,
                        nullable: true,
                        example: 1250.00
                    ),
                    new OA\Property(
                        property: 'service_type',
                        type: 'string',
                        enum: ['standard', 'express', 'same_day'],
                        example: 'express'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Pricing quote generated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'quote_id', type: 'string', example: 'QT-20250717 095312-ABC12'),
                        new OA\Property(property: 'pricing_quote_id', type: 'integer', example: 456),
                        new OA\Property(property: 'currency', type: 'string', example: 'NPR'),
                        new OA\Property(
                            property: 'service_type',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'code', type: 'string', example: 'express'),
                                new OA\Property(property: 'name', type: 'string', example: 'Express Delivery'),
                            ]
                        ),
                        new OA\Property(property: 'final_delivery_fee', type: 'number', format: 'float', example: 245.50),
                        new OA\Property(property: 'sla_due_at', type: 'string', format: 'date-time', example: '2025-07-18T14:30:00+05:45'),
                        new OA\Property(property: 'valid_until', type: 'string', format: 'date-time', example: '2025-07-17T12:00:00+05:45'),
                        new OA\Property(
                            property: 'pickup_branch',
                            type: 'object'
                        ),
                        new OA\Property(
                            property: 'delivery_branch',
                            type: 'object'
                        ),
                        new OA\Property(property: 'estimated_hours', type: 'integer', example: 12),
                        new OA\Property(
                            property: 'breakdown',
                            type: 'object',
                            additionalProperties: true
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid or inactive API key'
            ),
            new OA\Response(
                response: 403,
                description: 'API key does not have pricing permission'
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error'
            ),
        ]
    )]

    public function store(Request $request, MerchantApiKeyGuard $guard, PricingEngineService $pricingEngine)
    {
        $merchantKey = $guard->resolve($request);
        // dd('here');
        $validated = $request->validate([
            'pickup_address' => ['nullable', 'string', 'max:255'],
            'pickup_latitude' => ['required', 'numeric'],
            'pickup_longitude' => ['required', 'numeric'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'delivery_latitude' => ['required', 'numeric'],
            'delivery_longitude' => ['required', 'numeric'],
            'parcel_weight' => ['required', 'numeric', 'min:0.01'],
            'parcel_value' => ['nullable', 'numeric', 'min:0'],
            'payment_type' => ['required', 'in:cod,prepaid'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'service_type' => ['required', 'in:standard,express,same_day'],
        ]);

        $merchantId = $merchantKey->merchant_id ?? null;
        $quote = $pricingEngine->calculate($validated, $merchantId);
        $quoteNumber = 'QT-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(5));

        $quoteId = DB::table('pricing_quotes')->insertGetId($this->cols('pricing_quotes', [
            'quote_number' => $quoteNumber,
            'merchant_id' => $merchantId,
            'pickup_branch_id' => $quote['pickup_branch']['id'],
            'delivery_branch_id' => $quote['delivery_branch']['id'],
            'pickup_address' => $validated['pickup_address'] ?? null,
            'pickup_latitude' => $validated['pickup_latitude'],
            'pickup_longitude' => $validated['pickup_longitude'],
            'delivery_address' => $validated['delivery_address'] ?? null,
            'delivery_latitude' => $validated['delivery_latitude'],
            'delivery_longitude' => $validated['delivery_longitude'],
            'parcel_weight' => $validated['parcel_weight'],
            'parcel_value' => $validated['parcel_value'] ?? 0,
            'payment_type' => $validated['payment_type'],
            'cod_amount' => $validated['cod_amount'] ?? 0,
            'service_type' => $quote['service_type']['code'],
            'service_type_id' => $quote['service_type']['id'],
            'final_price' => $quote['final_price'],
            'sla_due_at' => $quote['sla_due_at'],
            'expires_at' => $quote['valid_until'],
            'snapshot_json' => json_encode($quote),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json([
            'success' => true,
            'quote_id' => $quoteNumber,
            'pricing_quote_id' => $quoteId,
            'currency' => $quote['currency'],
            'service_type' => $quote['service_type'],
            'final_delivery_fee' => $quote['final_price'],
            'sla_due_at' => $quote['sla_due_at']->toIso8601String(),
            'valid_until' => $quote['valid_until']->toIso8601String(),
            'pickup_branch' => $quote['pickup_branch'],
            'delivery_branch' => $quote['delivery_branch'],
            'estimated_hours' => $quote['estimated_hours'],
            'breakdown' => $quote['breakdown'],
        ]);
    }

    private function cols(string $table, array $data): array
    {
        return collect($data)->filter(fn($value, $column) => Schema::hasColumn($table, $column))->toArray();
    }
}
