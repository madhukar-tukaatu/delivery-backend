<?php

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rate\Services\Pricing\DefaultPricingImporter;

final class AdminPricingDefaultsController extends Controller
{
    public function preview(
        DefaultPricingImporter $importer
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $importer->preview(),
        ]);
    }

    public function import(
        Request $request,
        DefaultPricingImporter $importer
    ): JsonResponse {
        $validated = $request->validate([
            'activate' => ['sometimes', 'boolean'],
            'create_direct_routes' => ['sometimes', 'boolean'],
        ]);

        $result = $importer->import(
            userId: $request->user()?->id,
            activate: (bool) ($validated['activate'] ?? true),
            createDirectRoutes: (bool) (
                $validated['create_direct_routes'] ?? true
            )
        );

        return response()->json([
            'success' => true,
            'message' => 'Default pricing imported successfully.',
            'data' => $result,
        ]);
    }
}
