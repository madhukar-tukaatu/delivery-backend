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
            // Include the rider's completed history so the staff page can show
            // accurate Delivered and Failed tabs alongside active deliveries.
            $query->whereIn('status', ['assigned', 'accepted', 'out_for_delivery', 'delivered', 'failed']);
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

    public function arrived(Request $request, DeliveryAssignment $delivery)
    {
        return ApiResponse::success(
            $this->service->arrived($delivery, $request->user()),
            'Arrival at delivery location recorded.'
        );
    }

    /**
     * Create a Store Manager POD online payment session for this delivery.
     *
     * Request body (all optional):
     * - idempotency_key (string, max 191): retry-safe key; Express generates one when omitted.
     *
     * Amount, currency, merchant, and QR destination come from the shipment /
     * Store Manager - do NOT send amount or method here. Empty body `{}` is valid.
     *
     * Preconditions: assigned rider, out_for_delivery, arrived_at set, POD with
     * collectable amount, and merchant linked (external_store_id). Express always
     * calls Store Manager - it does not gate on STORE_MANAGER_PAYMENT_ENABLED.
     */
    public function createPaymentSession(Request $request, DeliveryAssignment $delivery)
    {
        $data = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:191'],
            // Reject accidental clients that try to override amount/method.
            'amount' => ['prohibited'],
            'payment_method' => ['prohibited'],
            'method' => ['prohibited'],
        ]);

        return ApiResponse::success(
            $this->service->createPaymentSession(
                $delivery,
                $request->user(),
                $data['idempotency_key'] ?? null,
            ),
            'Online payment session ready.'
        );
    }

    /**
     * Get (and optionally refresh) the current Store Manager payment session.
     *
     * Query: refresh=1 to poll Store Manager status for a pending session.
     */
    public function paymentSession(Request $request, DeliveryAssignment $delivery)
    {
        $request->validate([
            'refresh' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success(
            $this->service->paymentSession(
                $delivery,
                $request->user(),
                $request->boolean('refresh'),
            ),
            'Payment session status retrieved.'
        );
    }

    public function delivered(Request $request, DeliveryAssignment $delivery)
    {
        // Normalize checkbox / JSON truthy values for Laravel "accepted".
        $request->merge([
            'customer_confirmed' => filter_var(
                $request->input('customer_confirmed'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? $request->input('customer_confirmed'),
        ]);

        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'in:cash,online'],
            'payment_session_id' => ['nullable', 'required_if:payment_method,online', 'string', 'max:191'],
            'customer_confirmed' => ['required', 'accepted'],
            'customer_name' => ['required', 'string', 'max:191'],
            'customer_signature' => ['required', 'string', 'max:2097152'],
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
