<?php

declare(strict_types=1);

namespace Modules\POD\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\POD\Services\HamroPayPodPaymentService;
use Modules\Shipment\Models\Shipment;
use Throwable;

/**
 * Phase 6 gateway: POD online QR via HamroPay createSession.
 *
 * Store: POST /api/v1/store/pickup/orders/{id}/pod-qr
 *   internally calls this route with X-Tukaatu-Key / X-Tukaatu-Secret.
 *
 * Body: { merchant_order_id, external_order_id, amount }
 * tracking_number is accepted as an extra lookup key.
 */
final class GatewayPodPaymentController extends Controller
{
    public function __construct(
        private readonly HamroPayPodPaymentService $payments,
    ) {
    }

    /**
     * POST /api/v1/gateway/payments/pod-qr
     */
    public function createQr(Request $request): JsonResponse
    {
        $merchantId = (int) $request->attributes->get("merchant_id");
        abort_unless($merchantId > 0, 401, "Invalid merchant authentication.");

        $data = $request->validate([
            "tracking_number" => ["nullable", "string", "max:100"],
            "merchant_order_id" => ["nullable", "string", "max:100"],
            "external_order_id" => ["nullable", "string", "max:150"],
            "amount" => ["nullable", "numeric", "min:0.01"],
            "idempotency_key" => ["nullable", "string", "max:191"],
        ]);

        $tracking = trim((string) ($data["tracking_number"] ?? ""));
        $merchantOrderId = trim((string) ($data["merchant_order_id"] ?? ""));
        $externalOrderId = trim((string) ($data["external_order_id"] ?? ""));

        if ($tracking === "" && $merchantOrderId === "" && $externalOrderId === "") {
            return ApiResponse::error(
                "merchant_order_id or external_order_id is required.",
                422
            );
        }

        try {
            $shipment = $this->findShipment($merchantId, $tracking, $merchantOrderId, $externalOrderId);
            $payload = $this->payments->createGatewayQr(
                $shipment,
                $data["idempotency_key"] ?? null,
                isset($data["amount"]) ? (float) $data["amount"] : null,
            );

            return ApiResponse::success($payload, "POD QR session created.", 201);
        } catch (ValidationException $e) {
            return response()->json([
                "success" => false,
                "message" => "Unable to create POD QR session.",
                "errors" => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error("Unable to create POD QR session.", 500);
        }
    }

    /**
     * POST /api/v1/gateway/payments/pod-qr/verify
     */
    public function verify(Request $request): JsonResponse
    {
        $merchantId = (int) $request->attributes->get("merchant_id");
        abort_unless($merchantId > 0, 401, "Invalid merchant authentication.");

        $data = $request->validate([
            "merchant_txn_id" => ["required", "string", "max:80"],
        ]);

        $merchant = Merchant::query()->findOrFail($merchantId);

        try {
            $result = $this->payments->verifyMerchantTxn($merchant, $data["merchant_txn_id"]);

            return ApiResponse::success(
                $result,
                $result["paid"] ? "POD payment confirmed." : "POD payment not completed yet."
            );
        } catch (ValidationException $e) {
            return response()->json([
                "success" => false,
                "message" => "Unable to verify POD payment.",
                "errors" => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error("Unable to verify POD payment.", 500);
        }
    }

    private function findShipment(
        int $merchantId,
        string $tracking,
        string $merchantOrderId,
        string $externalOrderId,
    ): Shipment {
        $query = Shipment::query()->where("merchant_id", $merchantId);

        if ($tracking !== "") {
            $query->where("tracking_number", $tracking);
        } else {
            $ids = array_values(array_unique(array_filter([$merchantOrderId, $externalOrderId])));
            $query->where(function ($q) use ($ids) {
                $q->whereIn("merchant_order_id", $ids);
                if (\Illuminate\Support\Facades\Schema::hasColumn("shipments", "external_order_id")) {
                    $q->orWhereIn("external_order_id", $ids);
                }
            });
        }

        $shipment = $query->with("merchant")->latest("id")->first();

        if (! $shipment) {
            throw ValidationException::withMessages([
                "shipment" => ["Shipment was not found for this merchant."],
            ]);
        }

        return $shipment;
    }
}