<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ShipmentWorkflowService
{
    public function createPriceBreakdown(int $shipmentId, ?int $quoteId, array $snapshot): void
    {
        $b = $snapshot['breakdown'] ?? [];
        DB::table('shipment_price_breakdowns')->insert($this->cols('shipment_price_breakdowns', [
            'shipment_id'=>$shipmentId,
            'pricing_quote_id'=>$quoteId,
            'base_pickup_fee'=>$b['base_pickup_fee'] ?? 0,
            'base_delivery_fee'=>$b['base_delivery_fee'] ?? 0,
            'base_transfer_fee'=>$b['base_transfer_fee'] ?? 0,
            'pickup_extra_charge'=>$b['pickup_extra_charge'] ?? 0,
            'delivery_extra_charge'=>$b['delivery_extra_charge'] ?? 0,
            'weight_charge'=>$b['weight_charge'] ?? 0,
            'cod_fee'=>$b['cod_fee'] ?? 0,
            'discount'=>$b['discount'] ?? 0,
            'final_price'=>$b['final_price'] ?? $snapshot['final_price'] ?? 0,
            'snapshot_json'=>json_encode($snapshot),
            'created_at'=>now(),
            'updated_at'=>now(),
        ]));
    }

    public function createWorkflow(object $shipment): void
    {
        $priority = match ($shipment->service_type ?? 'standard') { 'same_day'=>'urgent', 'express'=>'high', default=>'normal' };
        $pickupTaskId = $this->createTask($shipment->id, $shipment->pickup_branch_id, 'pickup', $priority, 'Pickup pending from customer or merchant.');

        DB::table('shipments')->where('id',$shipment->id)->update($this->cols('shipments', ['current_task_id'=>$pickupTaskId,'status'=>'pickup_pending','updated_at'=>now()]));
        $this->log($shipment->id, null, 'pickup_pending', 'Shipment created and pickup task generated.');
        $this->notify($shipment->pickup_branch_id, $shipment->id, $pickupTaskId, 'New pickup pending', 'A new pickup task has been created.', 'pickup_pending');

        if ((int)$shipment->pickup_branch_id !== (int)$shipment->delivery_branch_id) {
            $transferTaskId = $this->createTask($shipment->id, $shipment->pickup_branch_id, 'branch_transfer', $priority, 'Transfer required to destination branch.');
            $this->notify($shipment->delivery_branch_id, $shipment->id, $transferTaskId, 'Incoming transfer expected', 'A shipment transfer is expected.', 'incoming_transfer');
        }

        $deliveryTaskId = $this->createTask($shipment->id, $shipment->delivery_branch_id, 'delivery', $priority, 'Delivery task pending.');
        $this->notify($shipment->delivery_branch_id, $shipment->id, $deliveryTaskId, 'Delivery task pending', 'A delivery task has been created.', 'delivery_pending');
    }

    public function assignTask(int $taskId, ?int $staffId, ?int $riderId, ?int $userId = null): object
    {
        DB::table('shipment_tasks')->where('id',$taskId)->update($this->cols('shipment_tasks', [
            'assigned_staff_id'=>$staffId,
            'assigned_rider_id'=>$riderId,
            'assigned_user_id'=>$userId,
            'status'=>'assigned',
            'assigned_at'=>now(),
            'updated_at'=>now(),
        ]));
        return DB::table('shipment_tasks')->where('id',$taskId)->first();
    }

    public function updateTaskStatus(int $taskId, string $status, ?string $note = null): object
    {
        $task = DB::table('shipment_tasks')->where('id',$taskId)->first();
        $dateColumn = ['accepted'=>'accepted_at','in_progress'=>'started_at','completed'=>'completed_at','failed'=>'failed_at'][$status] ?? null;
        $update = ['status'=>$status,'notes'=>$note,'updated_at'=>now()];
        if ($dateColumn) $update[$dateColumn] = now();
        DB::table('shipment_tasks')->where('id',$taskId)->update($this->cols('shipment_tasks',$update));

        if ($task) {
            $newShipmentStatus = $this->shipmentStatusFromTask($task->type, $status);
            if ($newShipmentStatus) {
                $old = DB::table('shipments')->where('id',$task->shipment_id)->value('status');
                DB::table('shipments')->where('id',$task->shipment_id)->update($this->cols('shipments', ['status'=>$newShipmentStatus,'current_task_id'=>$taskId,'updated_at'=>now()]));
                $this->log($task->shipment_id, $old, $newShipmentStatus, $note ?? 'Task status updated.');
            }
        }
        return DB::table('shipment_tasks')->where('id',$taskId)->first();
    }

    private function createTask(int $shipmentId, ?int $branchId, string $type, string $priority, string $notes): int
    {
        return DB::table('shipment_tasks')->insertGetId($this->cols('shipment_tasks', [
            'task_number'=>'TASK-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(5)),
            'shipment_id'=>$shipmentId,
            'branch_id'=>$branchId,
            'type'=>$type,
            'status'=>'pending',
            'priority'=>$priority,
            'due_at'=>now()->addDay(),
            'notes'=>$notes,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]));
    }

    private function log(int $shipmentId, ?string $old, string $new, ?string $note): void
    {
        DB::table('shipment_status_logs')->insert($this->cols('shipment_status_logs', [
            'shipment_id'=>$shipmentId,'old_status'=>$old,'new_status'=>$new,'changed_by'=>'system','note'=>$note,'meta_json'=>json_encode([]),'created_at'=>now(),'updated_at'=>now()
        ]));
    }

    private function notify(?int $branchId, int $shipmentId, ?int $taskId, string $title, string $message, string $type): void
    {
        DB::table('staff_notifications')->insert($this->cols('staff_notifications', [
            'title'=>$title,'message'=>$message,'branch_id'=>$branchId,'shipment_id'=>$shipmentId,'shipment_task_id'=>$taskId,'type'=>$type,'is_read'=>false,'data_json'=>json_encode([]),'created_at'=>now(),'updated_at'=>now()
        ]));
    }

    private function shipmentStatusFromTask(string $type, string $status): ?string
    {
        return match ($type . ':' . $status) {
            'pickup:assigned'=>'pickup_assigned',
            'pickup:accepted'=>'pickup_accepted',
            'pickup:in_progress'=>'pickup_in_progress',
            'pickup:completed'=>'picked_up',
            'pickup:failed'=>'pickup_failed',
            'branch_transfer:assigned'=>'transfer_assigned',
            'branch_transfer:in_progress'=>'transfer_in_progress',
            'branch_transfer:completed'=>'destination_branch_received',
            'branch_transfer:failed'=>'transfer_failed',
            'delivery:assigned'=>'delivery_assigned',
            'delivery:accepted'=>'delivery_accepted',
            'delivery:in_progress'=>'out_for_delivery',
            'delivery:completed'=>'delivered',
            'delivery:failed'=>'delivery_failed',
            default=>null,
        };
    }

    private function cols(string $table, array $data): array
    {
        return collect($data)->filter(fn($value,$column) => Schema::hasColumn($table,$column))->toArray();
    }
}
