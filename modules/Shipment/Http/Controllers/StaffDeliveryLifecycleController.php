<?php

declare(strict_types=1);

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shipment\Services\DeliveryWorkflowService;

final class StaffDeliveryLifecycleController extends Controller
{
    /**
     * Get deliveries assigned to the authenticated rider.
     */
    public function index(
        Request $request,
        DeliveryWorkflowService $service
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $service->assignedDeliveriesForRider(
                $request->user()->id
            ),
        ]);
    }

    /**
     * Accept delivery.
     */
    public function accept(
        int $delivery,
        Request $request,
        DeliveryWorkflowService $service
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $service->accept(
                $delivery,
                $request->user()->id
            ),
        ]);
    }

    /**
     * Mark shipment as out for delivery.
     */
    public function outForDelivery(
        int $delivery,
        Request $request,
        DeliveryWorkflowService $service
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $service->outForDelivery(
                $delivery,
                $request->user()->id
            ),
        ]);
    }

    /**
     * Mark shipment as delivered.
     */
    public function delivered(
        int $delivery,
        Request $request,
        DeliveryWorkflowService $service
    ): JsonResponse {

        $data = $request->validate([
            'otp' => [
                'nullable',
                'string',
                'max:20',
            ],

            'payment_method' => [
                'nullable',
                'in:cash,qr,card,wallet',
            ],

            'proof_photo_path' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'signature_path' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => $service->delivered(
                $delivery,
                $request->user()->id,
                $data
            ),
        ]);
    }

    /**
     * Mark delivery as failed.
     */
    public function failed(
        int $delivery,
        Request $request,
        DeliveryWorkflowService $service
    ): JsonResponse {

        $data = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:500',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => $service->failed(
                $delivery,
                $request->user()->id,
                $data
            ),
        ]);
    }
}