<?php

namespace Modules\Settlement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\POD\Models\PodRecord;
use Modules\Settlement\Models\MerchantSettlement;
use Modules\Settlement\Models\MerchantSettlementItem;
use Modules\Settlement\Services\SettlementHamroPayService;
use Modules\Settlement\Services\SettlementWorkflowService;
use Modules\Shipment\Models\Shipment;

class SettlementController extends Controller
{
    public function __construct(
        private SettlementWorkflowService $settlements,
        private SettlementHamroPayService $hamroPaySettlements,
    ) {}

    public function index(Request $request)
    {
        $query = MerchantSettlement::with('items')->latest();
        if ($request->user()->role === 'merchant') {
            $query->where('merchant_id', $request->user()->merchant_id);
        }
        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }

        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    /**
     * Cash POD waiting for branch deposit (and shipment fallbacks).
     * This is where a rider cash-complete lands BEFORE any settlement row exists.
     */
    public function pendingCash(Request $request)
    {
        $perPage = (int) $request->get('per_page', 100);

        $records = PodRecord::query()
            ->with('shipment')
            ->where('status', 'collected')
            ->where(function ($q) {
                $q->whereNull('payment_destination')
                    ->orWhere('payment_destination', '!=', 'merchant');
            })
            ->latest()
            ->limit($perPage)
            ->get();

        $coveredShipmentIds = $records->pluck('shipment_id')->filter()->all();

        // Fallback: shipment marked pending_deposit but POD row missing / wrong status.
        $orphanShipments = Shipment::query()
            ->where('status', 'delivered')
            ->where('settlement_status', 'pending_deposit')
            ->when($coveredShipmentIds !== [], fn ($q) => $q->whereNotIn('id', $coveredShipmentIds))
            ->latest('delivered_at')
            ->limit($perPage)
            ->get();

        $rows = $records->map(function (PodRecord $record) {
            return [
                'id' => $record->id,
                'source' => 'pod_record',
                'pod_record_id' => $record->id,
                'shipment_id' => $record->shipment_id,
                'merchant_id' => $record->merchant_id,
                'collected_by' => $record->collected_by,
                'pod_amount' => $record->pod_amount,
                'collected_amount' => $record->collected_amount,
                'status' => $record->status,
                'tracking_number' => $record->shipment?->tracking_number,
                'settlement_status' => $record->shipment?->settlement_status,
                'can_deposit' => true,
            ];
        })->values();

        foreach ($orphanShipments as $shipment) {
            $rows->push([
                'id' => 'shipment-'.$shipment->id,
                'source' => 'shipment_fallback',
                'pod_record_id' => null,
                'shipment_id' => $shipment->id,
                'merchant_id' => $shipment->merchant_id,
                'collected_by' => null,
                'pod_amount' => $shipment->pod_amount,
                'collected_amount' => $shipment->pod_amount,
                'status' => 'pending_deposit_no_pod_row',
                'tracking_number' => $shipment->tracking_number,
                'settlement_status' => $shipment->settlement_status,
                'can_deposit' => false,
            ]);
        }

        $readyCount = Shipment::query()
            ->where('status', 'delivered')
            ->where('settlement_status', 'ready')
            ->count();

        return ApiResponse::success([
            'pending_deposit' => $rows,
            'counts' => [
                'awaiting_branch_deposit' => $rows->count(),
                'ready_to_settle' => $readyCount,
                'settlements' => MerchantSettlement::query()->count(),
            ],
            'hint' => 'Cash POD from rider complete appears here first. Settlements table stays empty until you Generate settlement after deposit (or on_collection path).',
        ]);
    }

    /**
     * Preview what a settlement would include (accountable breakdown).
     */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'exists:merchants,id'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date'],
            'cash_path' => ['nullable', 'string', 'in:after_deposit,on_collection'],
            'adjustments' => ['nullable', 'numeric'],
        ]);

        $cashPath = $data['cash_path'] ?? SettlementWorkflowService::PATH_AFTER_DEPOSIT;
        $shipments = $this->settlements->eligibleShipments(
            (int) $data['merchant_id'],
            $cashPath,
            $data['period_from'] ?? null,
            $data['period_to'] ?? null,
        );

        $lines = [];
        $totalPod = 0.0;
        $totalDelivery = 0.0;
        $totalPodCharges = 0.0;

        foreach ($shipments as $shipment) {
            $line = $this->settlements->lineNet($shipment, $cashPath);
            $totalPod += $line['pod_amount'];
            $totalDelivery += $line['delivery_charge'];
            $totalPodCharges += $line['pod_charge'];
            $lines[] = [
                'shipment_id' => $shipment->id,
                'tracking_number' => $shipment->tracking_number,
                'payment_type' => $shipment->payment_type,
                'pod_status' => $shipment->pod_status,
                'settlement_status' => $shipment->settlement_status,
                'delivery_charge_paid_by' => $shipment->delivery_charge_paid_by,
                'delivered_at' => optional($shipment->delivered_at)->toIso8601String(),
                ...$line,
            ];
        }

        $adjustments = (float) ($data['adjustments'] ?? 0);
        $final = $totalPod + $adjustments; // delivery fees billed separately via invoices

        return ApiResponse::success([
            'cash_path' => $cashPath,
            'shipment_count' => count($lines),
            'total_pod_collected' => round($totalPod, 2),
            'total_delivery_charges' => round($totalDelivery, 2),
            'total_pod_charges' => round($totalPodCharges, 2),
            'adjustments' => round($adjustments, 2),
            'final_payable_amount' => round($final, 2),
            'lines' => $lines,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'exists:merchants,id'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date'],
            'adjustments' => ['nullable', 'numeric'],
            'cash_path' => ['nullable', 'string', 'in:after_deposit,on_collection'],
        ]);

        $cashPath = $data['cash_path'] ?? SettlementWorkflowService::PATH_AFTER_DEPOSIT;

        $settlement = DB::transaction(function () use ($data, $cashPath) {
            $shipments = $this->settlements->eligibleShipments(
                (int) $data['merchant_id'],
                $cashPath,
                $data['period_from'] ?? null,
                $data['period_to'] ?? null,
            );

            if ($shipments->isEmpty()) {
                throw ValidationException::withMessages([
                    'merchant_id' => [
                        'No accountable shipments to settle for this merchant (POD cash and/or merchant delivery charges).',
                    ],
                ]);
            }

            $totalPod = 0.0;
            $totalDelivery = 0.0;
            $totalPodCharges = 0.0;
            $built = [];

            foreach ($shipments as $shipment) {
                $line = $this->settlements->lineNet($shipment, $cashPath);
                if (! $line['include']) {
                    continue;
                }
                $totalPod += $line['pod_amount'];
                $totalDelivery += $line['delivery_charge'];
                $totalPodCharges += $line['pod_charge'];
                $built[] = [$shipment, $line];
            }

            if ($built === []) {
                throw ValidationException::withMessages([
                    'merchant_id' => ['Nothing to settle after applying delivery-charge and POD rules.'],
                ]);
            }

            $adjustments = (float) ($data['adjustments'] ?? 0);
            $final = $totalPod + $adjustments; // delivery fees billed separately via invoices

            $payload = [
                'merchant_id' => $data['merchant_id'],
                'settlement_number' => 'SET-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'period_from' => $data['period_from'] ?? null,
                'period_to' => $data['period_to'] ?? null,
                'total_pod_collected' => round($totalPod, 2),
                'total_delivery_charges' => round($totalDelivery, 2),
                'total_pod_charges' => round($totalPodCharges, 2),
                'adjustments' => round($adjustments, 2),
                'final_payable_amount' => round($final, 2),
                'status' => 'pending',
            ];

            if (Schema::hasColumn('merchant_settlements', 'cash_path')) {
                $payload['cash_path'] = $cashPath;
            }

            $settlement = MerchantSettlement::create($payload);

            foreach ($built as [$shipment, $line]) {
                $itemPayload = [
                    'merchant_settlement_id' => $settlement->id,
                    'shipment_id' => $shipment->id,
                    'pod_amount' => $line['pod_amount'],
                    'delivery_charge' => $line['delivery_charge'],
                    'pod_charge' => $line['pod_charge'],
                    'net_amount' => $line['net_amount'],
                ];
                MerchantSettlementItem::create($itemPayload);
                $shipment->update(['settlement_status' => 'processing']);
            }

            return $settlement;
        });

        return ApiResponse::success(
            $settlement->load('items'),
            'Settlement generated (POD cash + delivery charges).',
            201,
        );
    }

    public function markPaid(Request $request, MerchantSettlement $settlement)
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'string'],
            'bank_reference_number' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($settlement, $data, $request) {
            $settlement->update([
                'status' => 'settled',
                'payment_method' => $data['payment_method'] ?? null,
                'bank_reference_number' => $data['bank_reference_number'] ?? null,
                'settled_by' => $request->user()->id,
                'settled_at' => now(),
            ]);

            $shipmentIds = $settlement->items()->pluck('shipment_id');
            Shipment::whereIn('id', $shipmentIds)->update(['settlement_status' => 'settled']);

            PodRecord::whereIn('shipment_id', $shipmentIds)
                ->whereIn('status', ['deposited', 'collected'])
                ->where(function ($q) {
                    $q->whereNull('payment_destination')
                        ->orWhere('payment_destination', '!=', 'merchant');
                })
                ->update(['status' => 'settled', 'settled_at' => now()]);
        });

        return ApiResponse::success($settlement->fresh('items'), 'Settlement marked paid.');
    }

    public function show(MerchantSettlement $settlement)
    {
        return ApiResponse::success($settlement->load('items.shipment'));
    }

    public function payHamroPay(MerchantSettlement $settlement)
    {
        $session = $this->hamroPaySettlements->createPayoutSession($settlement);

        return ApiResponse::success($session, 'HamroPay checkout session created for POD settlement.');
    }

    public function confirmHamroPay(Request $request, MerchantSettlement $settlement)
    {
        $data = $request->validate([
            'merchant_txn_id' => ['required', 'string'],
        ]);

        $check = $this->hamroPaySettlements->confirmFromProvider($settlement, $data['merchant_txn_id']);
        if (! ($check['paid'] ?? false)) {
            return ApiResponse::error('HamroPay has not confirmed this payment yet.', 422, $check);
        }

        $request->merge([
            'payment_method' => 'hamropay',
            'bank_reference_number' => $data['merchant_txn_id'],
        ]);

        return $this->markPaid($request, $settlement);
    }

}
