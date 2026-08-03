<?php

namespace Modules\Rate\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Rate\Models\RateCard;

class RateCardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(
            max(
                (int) $request->input('per_page', 20),
                1
            ),
            100
        );

        $query = RateCard::query()
            ->when(
                $request->filled('status'),
                function ($query) use ($request): void {
                    $query->where(
                        'status',
                        $request->string('status')->toString()
                    );
                }
            )
            ->orderBy('name');

        return response()->json([
            'data' => $query->paginate($perPage),
        ]);
    }
}