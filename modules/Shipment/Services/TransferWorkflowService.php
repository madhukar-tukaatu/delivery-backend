<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shipment\Models\Shipment;

class TransferWorkflowService
{
    public function __construct(private TrackingNumberService $trackingNumberService)
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

        $batchId = DB::table('transfer_batches')->insertGetId([
            'batch_number' => $this->trackingNumberService->transferBatchNumber(),
            'origin_branch_id' => $shipment->origin_branch_id,
            'origin_sub_branch_id' => $shipment->origin_sub_branch_id,
            'destination_branch_id' => $shipment->destination_branch_id,
            'destination_sub_branch_id' => $shipment->destination_sub_branch_id,
            'vehicle_id' => $payload['vehicle_id'] ?? null,
            'vehicle_number' => $payload['vehicle_number'] ?? null,
            'seal_number' => $payload['seal_number'] ?? null,
            'status' => 'created',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transfer_batch_items')->insert([
            'transfer_batch_id' => $batchId,
            'shipment_id' => $shipment->id,
            'status' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->track($shipment->id, $actorId, 'transfer_created', 'Transfer batch created', 'Parcel added to transfer batch.');

        return DB::table('transfer_batches')->where('id', $batchId)->first();
    }

    public function dispatchTransfer(int $batchId, int $actorId): object
    {
        $batch = DB::table('transfer_batches')->where('id', $batchId)->first();
        abort_unless($batch, 404, 'Transfer batch not found.');

        DB::table('transfer_batches')->where('id', $batchId)->update([
            'status' => 'dispatched',
            'dispatched_by' => $actorId,
            'dispatched_at' => now(),
            'updated_at' => now(),
        ]);

        $items = DB::table('transfer_batch_items')->where('transfer_batch_id', $batchId)->get();
        foreach ($items as $item) {
            DB::table('transfer_batch_items')->where('id', $item->id)->update([
                'status' => 'dispatched',
                'scanned_out_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('shipments')->where('id', $item->shipment_id)->update([
                'status' => 'in_transit',
                'updated_at' => now(),
            ]);

            $this->track($item->shipment_id, $actorId, 'in_transit', 'Transfer dispatched', 'Parcel dispatched to destination branch.');
        }

        return DB::table('transfer_batches')->where('id', $batchId)->first();
    }

    public function receiveTransfer(int $batchId, int $actorId): object
    {
        $batch = DB::table('transfer_batches')->where('id', $batchId)->first();
        abort_unless($batch, 404, 'Transfer batch not found.');

        DB::table('transfer_batches')->where('id', $batchId)->update([
            'status' => 'received',
            'received_by' => $actorId,
            'received_at' => now(),
            'updated_at' => now(),
        ]);

        $items = DB::table('transfer_batch_items')->where('transfer_batch_id', $batchId)->get();
        foreach ($items as $item) {
            DB::table('transfer_batch_items')->where('id', $item->id)->update([
                'status' => 'received',
                'scanned_in_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('shipments')->where('id', $item->shipment_id)->update([
                'status' => 'at_destination_hub',
                'current_branch_id' => $batch->destination_branch_id,
                'current_sub_branch_id' => $batch->destination_sub_branch_id,
                'updated_at' => now(),
            ]);

            $this->track($item->shipment_id, $actorId, 'at_destination_hub', 'Received at destination hub', 'Parcel received at destination branch.', $batch->destination_branch_id, $batch->destination_sub_branch_id);
        }

        return DB::table('transfer_batches')->where('id', $batchId)->first();
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
