<?php

namespace Modules\Rate\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rate\Models\PricingQuote;

class AdminPricingQuoteController
{
    public function index(Request $request): JsonResponse
    {
        $quotes = PricingQuote::query()
            ->with([
                'originBranch:id,name,code',
                'destinationBranch:id,name,code',
            ])
            ->when(
                $request->filled('quote_uuid'),
                fn ($query) => $query->where(
                    'quote_uuid',
                    $request->string('quote_uuid')
                )
            )
            ->when(
                $request->filled('origin_branch_id'),
                fn ($query) => $query->where(
                    'origin_branch_id',
                    $request->integer('origin_branch_id')
                )
            )
            ->when(
                $request->filled('destination_branch_id'),
                fn ($query) => $query->where(
                    'destination_branch_id',
                    $request->integer('destination_branch_id')
                )
            )
            ->latest('id')
            ->paginate(
                min(
                    max($request->integer('per_page', 20), 1),
                    100
                )
            );

        return response()->json([
            'success' => true,
            'data' => $quotes,
        ]);
    }

    public function show(
        PricingQuote $pricingQuote
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $pricingQuote->load([
                'originBranch:id,name,code',
                'destinationBranch:id,name,code',
                'setting',
                'routeRate',
            ]),
        ]);
    }
}