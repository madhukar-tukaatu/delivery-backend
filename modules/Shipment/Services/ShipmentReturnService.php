<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use App\Events\ShipmentStatusUpdated;
use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Shipment\Models\Shipment;
use Modules\Tracking\Services\TrackingService;
use Modules\Webhook\Services\WebhookService;

class ShipmentReturnService
{
    public function __construct(
        private readonly TrackingService $trackingService,
        private readonly WebhookService $webhookService,
        private readonly ShipmentCallbackService $callbackService,
    ) {
    }

    /**
     * Initiate return for an active or failed shipment (RTO).
     */
    public function initiateReturn(
        Shipment $shipment,
        string $reason,
        ?User $actor = null,
        string $source = 'admin'
    ): Shipment {
        return DB::transaction(function () use ($shipment, $reason, $actor, $source) {
            $shipment = Shipment::query()
                ->lockForUpdate()
                ->findOrFail($shipment->id);

            // Cannot initiate return for terminal states
            if (in_array($shipment->status, [
                CourierStatus::DELIVERED,
                CourierStatus::CANCELLED,
                CourierStatus::RETURNED_TO_MERCHANT,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot initiate return for a {$shipment->status} shipment."],
                ]);
            }

            // Cancel any active last-mile delivery assignment
            if (DB::getSchemaBuilder()->hasTable('delivery_assignments')) {
                DB::table('delivery_assignments')
                    ->where('shipment_id', $shipment->id)
                    ->whereIn('status', ['pending', 'assigned', 'accepted', 'out_for_delivery'])
                    ->update([
                        'status' => 'cancelled',
                        'updated_at' => now(),
                    ]);
            }

            if (DB::getSchemaBuilder()->hasTable('delivery_run_items')) {
                DB::table('delivery_run_items')
                    ->where('shipment_id', $shipment->id)
                    ->where('status', '!=', 'removed')
                    ->update([
                        'status' => 'removed',
                        'updated_at' => now(),
                    ]);
            }

            $oldStatus = $shipment->status;
            $currentBranchId = $shipment->current_branch_id ?? $shipment->destination_branch_id;
            $originBranchId = $shipment->origin_branch_id;

            // If the parcel is already physically at the origin branch node, it is ready for return handover
            $isAlreadyAtOrigin = ($currentBranchId && $originBranchId && (int) $currentBranchId === (int) $originBranchId);

            $targetStatus = $isAlreadyAtOrigin
                ? CourierStatus::RETURNED_TO_ORIGIN
                : CourierStatus::RETURN_INITIATED;

            $shipment->status = $targetStatus;
            $shipment->merchant_status = CourierStatus::merchantStatus($targetStatus);
            $shipment->remarks = $reason;
            $shipment->save();

            $description = $isAlreadyAtOrigin
                ? "Return initiated ({$source}). Parcel is at origin branch and ready for handover to merchant. Reason: {$reason}"
                : "Return initiated ({$source}). Parcel queued for reverse transit to origin branch. Reason: {$reason}";

            $this->trackingService->record(
                $shipment->fresh(),
                $targetStatus,
                $description,
                $actor?->id
            );

            $fresh = $shipment->fresh();

            // 1. Notify store via Webhooks
            try {
                $this->webhookService->queueShipmentEvent($fresh, 'shipment.return_initiated');
                if ($isAlreadyAtOrigin) {
                    $this->webhookService->queueShipmentEvent($fresh, 'shipment.returned_to_origin');
                }
            } catch (\Throwable $e) {
                report($e);
            }

            // 2. Notify store partner via integration callbacks
            try {
                $this->callbackService->returnInitiated($fresh, [
                    'reason' => $reason,
                    'initiated_by' => $source,
                    'is_at_origin' => $isAlreadyAtOrigin,
                ]);

                if ($isAlreadyAtOrigin) {
                    $this->callbackService->returnedToOrigin($fresh, [
                        'remarks' => 'Parcel ready at origin branch.',
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }

            // 3. Broadcast status update event
            try {
                if (class_exists(ShipmentStatusUpdated::class)) {
                    event(new ShipmentStatusUpdated($fresh));
                }
            } catch (\Throwable $e) {
            }

            return $fresh;
        });
    }

    /**
     * Mark parcel as received back at origin branch from reverse transit.
     */
    public function markReturnedToOrigin(
        Shipment $shipment,
        ?User $actor = null,
        ?string $remarks = null
    ): Shipment {
        return DB::transaction(function () use ($shipment, $actor, $remarks) {
            $shipment = Shipment::query()
                ->lockForUpdate()
                ->findOrFail($shipment->id);

            if (! in_array($shipment->status, [
                CourierStatus::RETURN_INITIATED,
                CourierStatus::IN_TRANSIT,
                CourierStatus::SORTED_FOR_TRANSFER,
                CourierStatus::DELIVERY_FAILED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot mark as returned to origin from status: {$shipment->status}."],
                ]);
            }

            $shipment->status = CourierStatus::RETURNED_TO_ORIGIN;
            $shipment->merchant_status = CourierStatus::merchantStatus(CourierStatus::RETURNED_TO_ORIGIN);
            $shipment->current_branch_id = $shipment->origin_branch_id;
            $shipment->current_sub_branch_id = $shipment->origin_sub_branch_id;
            if ($remarks) {
                $shipment->remarks = $remarks;
            }
            $shipment->save();

            $description = "Parcel received back at origin branch. Ready for return handover to merchant." . ($remarks ? " Remarks: {$remarks}" : "");

            $this->trackingService->record(
                $shipment->fresh(),
                CourierStatus::RETURNED_TO_ORIGIN,
                $description,
                $actor?->id
            );

            $fresh = $shipment->fresh();

            // Notify store
            try {
                $this->webhookService->queueShipmentEvent($fresh, 'shipment.returned_to_origin');
            } catch (\Throwable $e) {
                report($e);
            }

            try {
                $this->callbackService->returnedToOrigin($fresh, [
                    'remarks' => $remarks,
                    'received_at' => now()->toIso8601String(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            try {
                if (class_exists(ShipmentStatusUpdated::class)) {
                    event(new ShipmentStatusUpdated($fresh));
                }
            } catch (\Throwable $e) {
            }

            return $fresh;
        });
    }

    /**
     * Hand over the returned parcel back to the merchant.
     */
    public function markReturnedToMerchant(
        Shipment $shipment,
        ?User $actor = null,
        array $data = []
    ): Shipment {
        return DB::transaction(function () use ($shipment, $actor, $data) {
            $shipment = Shipment::query()
                ->lockForUpdate()
                ->findOrFail($shipment->id);

            if (! in_array($shipment->status, [
                CourierStatus::RETURNED_TO_ORIGIN,
                CourierStatus::RETURN_INITIATED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot mark returned to merchant from status: {$shipment->status}."],
                ]);
            }

            $recipientName = $data['receiver_name'] ?? $data['recipient_name'] ?? $shipment->sender_name ?? 'Merchant staff';

            $shipment->status = CourierStatus::RETURNED_TO_MERCHANT;
            $shipment->merchant_status = CourierStatus::merchantStatus(CourierStatus::RETURNED_TO_MERCHANT);
            $shipment->pod_status = 'cancelled';
            $shipment->settlement_status = 'cancelled';
            if (! empty($data['remarks'])) {
                $shipment->remarks = $data['remarks'];
            }
            $shipment->save();

            $description = "Parcel returned to merchant successfully. Received by: {$recipientName}." . (! empty($data['remarks']) ? " Remarks: {$data['remarks']}" : "");

            $this->trackingService->record(
                $shipment->fresh(),
                CourierStatus::RETURNED_TO_MERCHANT,
                $description,
                $actor?->id
            );

            $fresh = $shipment->fresh();

            // Notify store
            try {
                $this->webhookService->queueShipmentEvent($fresh, 'shipment.returned_to_merchant');
            } catch (\Throwable $e) {
                report($e);
            }

            try {
                $this->callbackService->returnedToMerchant($fresh, [
                    'received_by' => $recipientName,
                    'remarks' => $data['remarks'] ?? null,
                    'returned_at' => now()->toIso8601String(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            try {
                if (class_exists(ShipmentStatusUpdated::class)) {
                    event(new ShipmentStatusUpdated($fresh));
                }
            } catch (\Throwable $e) {
            }

            return $fresh;
        });
    }
}
