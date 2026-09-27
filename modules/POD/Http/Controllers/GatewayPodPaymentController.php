<?php

declare(strict_types=1);

namespace Modules\POD\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\POD\Services\StoreManagerPaymentService;
use Modules\Shipment\Models\Shipment;
use Throwable;

/**
 * Store Manager gateway: POD online QR via HamroPay (Express fetches createSession).
 *
 * Auth: merchant.api-key (X-Tukaatu-Key / X-Tukaatu-Secret) — same as shipments/pickups.
 */
final class GatewayPodPaymentController extends Controller
{
    public function __construct(
        private readonly StoreManagerPaymentService $payments,
    ) {
    }

    /**
     * POST /api/v1/gateway/payments/pod-qr
     */
    public function createQr(Request $request): JsonResponse
    {
        $merchantId = (int) $request->attributes->get('merchant_id');
        abort_unless($merchantId > 0, 401, 'Invalid merchant authentication.');

        $data = $request->validate([
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'merchant_order_id' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);

        if (empty($data['tracking_number']) && empty($data['merchant_order_id'])) {
            return ApiResponse::error('tracking_number or merchant_order_id is required.', 422);
        }

        $shipment = $this->findShipment($merchantId, $data);

        try {
            $payload = $this->payments->createGatewayQr(
                $shipment,
                $data['idempotency_key'] ?? null,
            );

            return ApiResponse::success($payload, 'POD QR session created.', 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to create POD QR session.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to create POD QR session.', 500);
        }
    }

    /**
     * POST /api/v1/gateway/payments/pod-qr/verify
     */
    public function verify(Request $request): JsonResponse
    {
        $merchantId = (int) $request->attributes->get('merchant_id');
        abort_unless($merchantId > 0, 401, 'Invalid merchant authentication.');

        $data = $request->validate([
            'merchant_txn_id' => ['required', 'string', 'max:80'],
        ]);

        $merchant = Merchant::query()->findOrFail($merchantId);

        try {
            $result = $this->payments->verifyMerchantTxn($merchant, $data['merchant_txn_id']);

            return ApiResponse::success($result, $result['paid'] ? 'POD payment confirmed.' : 'POD payment not completed yet.');
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to verify POD payment.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to verify POD payment.', 500);
        }
    }

    /**
     * @param  array{tracking_number?: string, merchant_order_id?: string}  $data
     */
    private function findShipment(int $merchantId, array $data): Shipment
    {
        $query = Shipment::query()->where('merchant_id', $merchantId);

        if (! empty($data['tracking_number'])) {
            $query->where('tracking_number', $data['tracking_number']);
        } else {
            $query->where('merchant_order_id', $data['merchant_order_id']);
        }

        $shipment = $query->with('merchant')->first();

        if (! $shipment) {
            throw ValidationException::withMessages([
                'shipment' => ['Shipment was not found for this merchant.'],
            ]);
        }

        return $shipment;
    }
}