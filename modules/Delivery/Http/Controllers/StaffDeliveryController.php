<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Delivery\Services\DeliveryWorkflowService;

class StaffDeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryWorkflowService $service,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $query = DeliveryAssignment::query()
            ->with([
                'shipment.merchant',
                'shipment.destinationBranch',
            ])
            ->where('rider_id', $user->id);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        } else {
            $query->whereIn('status', ['assigned', 'accepted', 'out_for_delivery']);
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return ApiResponse::success($query->latest('id')->paginate($perPage));
    }

    public function accept(Request $request, DeliveryAssignment $delivery)
    {
        return ApiResponse::success(
            $this->service->accept($delivery, $request->user()),
            'Delivery accepted.'
        );
    }

    public function outForDelivery(Request $request, DeliveryAssignment $delivery)
    {
        return ApiResponse::success(
            $this->service->outForDelivery($delivery, $request->user()),
            'Marked out for delivery.'
        );
    }

    public function delivered(Request $request, DeliveryAssignment $delivery)
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'in:cash,qr,card,wallet'],
            'pod_collected_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::success(
            $this->service->delivered($delivery, $request->user(), $data),
            'Shipment delivered.'
        );
    }

    public function failed(Request $request, DeliveryAssignment $delivery)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return ApiResponse::success(
            $this->service->failed($delivery, $request->user(), $data['reason']),
            'Delivery marked as failed.'
        );
    }
}
