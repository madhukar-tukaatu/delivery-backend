<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shipment\Models\Shipment;
use Modules\Rate\Services\ConfiguredTransferRouteService;

class TransferWorkflowService
{
    public function __construct(
        private TrackingNumberService $trackingNumberService,
        private ConfiguredTransferRouteService $transferRouteService
    )
    {
    }

    public function receiveOrigin(Shipment $shipment, int $actorId, ?string $note = null): Shipment
    {
        DB::table('shipments')->where('id', $shipment->id)->update([
            'status' => 'at_origin_hub',
            'current_branch_id' => $shipment->origin_branch_id,
            'current_sub_branch_id' => $shipment->origin_sub_branch_id,
            'updated_at' => now(),
        ]);

        $this->track($shipment->id, $actorId, 'at_origin_hub', 'Received at origin hub', $note ?: 'Parcel scanned at origin hub.', $shipment->origin_branch_id, $shipment->origin_sub_branch_id);

        return $shipment->fresh();
    }

    public function createTransfer(Shipment $shipment, int $actorId, array $payload = []): object
    {
        abort_unless($shipment->requires_transfer, 422, 'This shipment does not require transfer.');

        // Resolve the configured transfer route
        $route = $this->transferRouteService->resolve(
            originBranchId: (int) $shipment->origin_branch_id,
            destinationBranchId: (int) $shipment->destination_branch_id,
            serviceType: (string) ($shipment->service_type ?? 'standard')
        );

        $batchId = DB::table('shipment_transfer_batches')->insertGetId([
            'batch_number' => $this->trackingNumberService->transferBatchNumber(),
            'from_branch_id' => $shipment->origin_branch_id,
            'from_sub_branch_id' => $shipment->origin_sub_branch_id,
            'to_branch_id' => $shipment->destination_branch_id,
            'to_sub_branch_id' => $shipment->destination_sub_branch_id,
            'vehicle_number' => $payload['vehicle_number'] ?? null,
            'driver_id' => $payload['driver_id'] ?? null,
            'status' => 'created',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
            
            // Store route details
            'transfer_route_id' => (int) ($route['route_id'] ?? null),
            'route_code' => (string) ($route['route_code'] ?? null),
            'route_name' => (string) ($route['route_name'] ?? null),
            'transfer_count' => (int) ($route['transfer_count'] ?? 0),
            'transit_count' => (int) ($route['transit_count'] ?? 0),
            'transit_branch_ids' => json_encode($route['transit_branches'] ?? []),
            'path' => json_encode($route['path'] ?? []),
            'path_text' => (string) ($route['path_text'] ?? null),
            'total_distance_km' => (float) ($route['total_distance_km'] ?? 0),
            'total_estimated_hours' => (int) ($route['total_estimated_hours'] ?? 0),
        ]);

        DB::table('shipment_transfer_batch_items')->insert([
            'transfer_batch_id' => $batchId,
            'shipment_id' => $shipment->id,
            'status' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->track($shipment->id, $actorId, 'transfer_created', 'Transfer batch created', 'Parcel added to transfer batch using route ' . ($route['route_code'] ?? 'N/A'));

        return DB::table('shipment_transfer_batches')->where('id', $batchId)->first();
    }

    public function dispatchTransfer(int $batchId, int $actorId): object
    {
        $batch = DB::table('shipment_transfer_batches')->where('id', $batchId)->first();
        abort_unless($batch, 404, 'Transfer batch not found.');

        DB::table('shipment_transfer_batches')->where('id', $batchId)->update([
            'status' => 'dispatched',
            'dispatched_by' => $actorId,
            'dispatched_at' => now(),
            'updated_at' => now(),
        ]);

        $items = DB::table('shipment_transfer_batch_items')->where('transfer_batch_id', $batchId)->get();
        foreach ($items as $item) {
            DB::table('shipment_transfer_batch_items')->where('id', $item->id)->update([
                'status' => 'dispatched',
                'scanned_out_at' => now(),
                'updated_at' => now(),
            ]);

            // Apply route details to shipment
            DB::table('shipments')->where('id', $item->shipment_id)->update([
                'status' => 'in_transit',
                'transfer_route_id' => (int) ($batch->transfer_route_id ?? null),
                'route_code' => (string) ($batch->route_code ?? null),
                'route_name' => (string) ($batch->route_name ?? null),
                'transfer_count' => (int) ($batch->transfer_count ?? 0),
                'transit_count' => (int) ($batch->transit_count ?? 0),
                'transit_branch_ids' => $batch->transit_branch_ids,
                'route_path' => $batch->path,
                'path_text' => (string) ($batch->path_text ?? null),
                'total_distance_km' => (float) ($batch->total_distance_km ?? 0),
                'total_estimated_hours' => (int) ($batch->total_estimated_hours ?? 0),
                'updated_at' => now(),
            ]);

            $this->track(
                $item->shipment_id,
                $actorId,
                'in_transit',
                'Transfer dispatched',
                'Parcel dispatched via route ' . ($batch->route_code ?? 'N/A') . ' (' . ($batch->path_text ?? 'N/A') . ')'
            );
        }

        return DB::table('shipment_transfer_batches')->where('id', $batchId)->first();
    }

    public function receiveTransfer(int $batchId, int $actorId): object
    {
        $batch = DB::table('shipment_transfer_batches')->where('id', $batchId)->first();
        abort_unless($batch, 404, 'Transfer batch not found.');

        DB::table('shipment_transfer_batches')->where('id', $batchId)->update([
            'status' => 'received',
            'received_by' => $actorId,
            'received_at' => now(),
            'updated_at' => now(),
        ]);

        $items = DB::table('shipment_transfer_batch_items')->where('transfer_batch_id', $batchId)->get();
        foreach ($items as $item) {
            DB::table('shipment_transfer_batch_items')->where('id', $item->id)->update([
                'status' => 'received',
                'scanned_in_at' => now(),
                'updated_at' => now(),
            ]);

            // Update shipment: move to destination hub and mark as arrived
            DB::table('shipments')->where('id', $item->shipment_id)->update([
                'status' => 'at_destination_hub',
                'current_branch_id' => (int) ($batch->to_branch_id ?? null),
                'current_sub_branch_id' => (int) ($batch->to_sub_branch_id ?? null),
                'updated_at' => now(),
            ]);

            $this->track(
                $item->shipment_id,
                $actorId,
                'at_destination_hub',
                'Received at destination hub',
                'Parcel received at destination via route ' . ($batch->route_code ?? 'N/A')
            );
        }

        return DB::table('shipment_transfer_batches')->where('id', $batchId)->first();
    }

    private function track(int $shipmentId, int $actorId, string $status, string $title, string $description, $branchId = null, $subBranchId = null): void
    {
        DB::table('shipment_tracking_events')->insert([
            'shipment_id' => $shipmentId,
            'actor_id' => $actorId,
            'status' => $status,
            'title' => $title,
            'description' => $description,
            'branch_id' => $branchId,
            'sub_branch_id' => $subBranchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
