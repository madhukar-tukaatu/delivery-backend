<?php

namespace Modules\Dispatch\Services;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Services\DeliveryWorkflowService;
use Modules\Shipment\Models\Shipment;
use Modules\Tracking\Services\TrackingService;
use Modules\Webhook\Services\WebhookService;

class RouteWorkflowService
{
    public function __construct(
        private TrackingService $trackingService,
        private WebhookService $webhookService,
    ) {}

    public function receiveOriginSubBranch(Shipment $shipment, User $user, ?string $remarks = null): Shipment
    {
        return DB::transaction(function () use ($shipment, $user, $remarks) {
            abort_unless(in_array($shipment->status, [CourierStatus::PICKED_UP, CourierStatus::PICKUP_ASSIGNED], true), 422, 'Shipment must be picked up first.');

            $shipment->update([
                'status' => CourierStatus::RECEIVED_AT_ORIGIN_SUB_BRANCH,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::RECEIVED_AT_ORIGIN_SUB_BRANCH),
                'current_branch_id' => $shipment->origin_branch_id,
                'current_sub_branch_id' => $shipment->origin_sub_branch_id,
            ]);

            $firstStep = $shipment->routeSteps()->orderBy('sequence')->first();
            if ($firstStep && $firstStep->status === 'pending') {
                $firstStep->update(['status' => 'ready']);
            }

            $this->trackingService->record($shipment->fresh(), CourierStatus::RECEIVED_AT_ORIGIN_SUB_BRANCH, $remarks ?: 'Received at origin sub-branch.', $user->id);
            $this->webhookService->queueShipmentEvent($shipment->fresh(), 'origin.sub_branch_received');

            return $shipment->fresh(['routeSteps']);
        });
    }

    public function dispatchNextStep(Shipment $shipment, User $user, ?string $remarks = null): Shipment
    {
        return DB::transaction(function () use ($shipment, $user, $remarks) {
            $step = $shipment->routeSteps()
                ->whereIn('status', ['ready', 'pending'])
                ->orderBy('sequence')
                ->firstOrFail();

            $step->update([
                'status' => 'in_transit',
                'departed_at' => now(),
                'dispatched_at' => now(),
            ]);

            $shipment->update([
                'status' => CourierStatus::IN_TRANSIT,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::IN_TRANSIT),
                'current_branch_id' => $step->from_branch_id,
                'current_sub_branch_id' => null,
            ]);

            $this->trackingService->record($shipment->fresh(), CourierStatus::IN_TRANSIT, $remarks ?: 'Dispatched to next route point.', $user->id);
            $this->webhookService->queueShipmentEvent($shipment->fresh(), 'shipment.in_transit');

            return $shipment->fresh(['routeSteps']);
        });
    }

    public function receiveCurrentStep(Shipment $shipment, User $user, ?string $remarks = null): Shipment
    {
        return DB::transaction(function () use ($shipment, $user, $remarks) {
            $step = $shipment->routeSteps()
                ->where('status', 'in_transit')
                ->orderBy('sequence')
                ->firstOrFail();

            $step->update([
                'status' => 'received',
                'received_by' => $user->id,
                'received_at' => now(),
            ]);

            $lastStep = !$shipment->routeSteps()
                ->where('sequence', '>', $step->sequence)
                ->exists();

            $status = $lastStep
                ? ($shipment->destination_sub_branch_id ? CourierStatus::RECEIVED_AT_DESTINATION_SUB_BRANCH : CourierStatus::RECEIVED_AT_DESTINATION_BRANCH)
                : CourierStatus::RECEIVED_AT_TRANSIT_HUB;

            $shipment->update([
                'status' => $status,
                'merchant_status' => CourierStatus::merchantStatus($status),
                'current_branch_id' => $step->to_branch_id,
                'current_sub_branch_id' => $lastStep ? $shipment->destination_sub_branch_id : null,
            ]);

            $nextStep = $shipment->routeSteps()
                ->where('sequence', '>', $step->sequence)
                ->where('status', 'pending')
                ->orderBy('sequence')
                ->first();

            if ($nextStep) {
                $nextStep->update(['status' => 'ready']);
            } else {
                app(DeliveryWorkflowService::class)->createDeliveryAssignment($shipment->fresh());
            }

            $this->trackingService->record($shipment->fresh(), $status, $remarks ?: 'Shipment received at route point.', $user->id);
            $this->webhookService->queueShipmentEvent($shipment->fresh(), $lastStep ? 'destination.received' : 'transit.received');

            return $shipment->fresh(['routeSteps', 'deliveryAssignment']);
        });
    }
}
