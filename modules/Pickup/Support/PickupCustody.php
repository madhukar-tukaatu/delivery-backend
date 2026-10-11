<?php

declare(strict_types=1);

namespace Modules\Pickup\Support;

use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who physically holds a pickup parcel, and what the branch can still do with it.
 *
 * A parcel collected by a rider is NOT in the branch's custody until the branch
 * verifies it on the pickup (Receive). Until then it must not be offered for
 * Transfers outbound / TR loading / dispatch, because the branch never checked
 * it in. That gap is how PR-000025's parcels went "in transit" while the pickup
 * still waited for verification.
 */
final class PickupCustody
{
    /** Shipment statuses that mean "still with the rider / merchant". */
    public const PRE_BRANCH_STATUSES = [
        CourierStatus::BOOKED,
        CourierStatus::AWAITING_PICKUP,
        CourierStatus::PICKUP_ASSIGNED,
        CourierStatus::PICKED_UP,
    ];

    /** Pickup item statuses that mean the branch has not verified the parcel. */
    public const UNVERIFIED_ITEM_STATUSES = [
        PickupShipmentStatus::PENDING,
        PickupShipmentStatus::COLLECTED,
    ];

    /** Shipment statuses meaning the branch already accepted the parcel. */
    public const BRANCH_ACCEPTED_STATUSES = [
        CourierStatus::RECEIVED_AT_ORIGIN_SUB_BRANCH,
        CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
        CourierStatus::SORTED_FOR_DELIVERY,
        CourierStatus::SORTED_FOR_TRANSFER,
    ];

    /** Legacy in-transit statuses. */
    public const IN_TRANSIT_STATUSES = [
        CourierStatus::IN_TRANSIT,
        CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
    ];

    /** TR states in which the parcel really left the branch. */
    private const LEFT_MANIFEST_STATUSES = ['dispatched', 'in_transit', 'partially_received', 'received'];

    private const LEFT_ITEM_STATUSES = ['sent', 'dispatched', 'in_transit', 'received', 'missing', 'found', 'lost', 'extra'];

    /**
     * Exclude parcels that are still in a pickup the branch has not verified.
     * Use on any "ready to leave the branch" query (Transfers outbound, TR add,
     * badges). $idColumn / $statusColumn reference the outer shipments row.
     */
    public static function excludeAwaitingBranchReceive($query, string $idColumn = 'shipments.id', string $statusColumn = 'shipments.status'): void
    {
        $query->where(function ($outer) use ($idColumn, $statusColumn) {
            $outer->whereNotIn($statusColumn, self::PRE_BRANCH_STATUSES)
                ->orWhereNotExists(function ($q) use ($idColumn) {
                    $q->selectRaw('1')
                        ->from('pickup_request_shipments as prs_cust')
                        ->join('pickup_requests as pr_cust', 'pr_cust.id', '=', 'prs_cust.pickup_request_id')
                        ->whereColumn('prs_cust.shipment_id', $idColumn)
                        ->whereNull('prs_cust.removed_at')
                        ->whereIn('prs_cust.status', self::UNVERIFIED_ITEM_STATUSES)
                        ->whereIn('pr_cust.status', PickupStatus::active());
                });
        });
    }

    /**
     * The open pickup (request number) still waiting for the branch to verify
     * this parcel, or null when the branch has custody.
     */
    public static function pendingPickupNumber(object $shipment): ?string
    {
        if (! in_array((string) ($shipment->status ?? ''), self::PRE_BRANCH_STATUSES, true)) {
            return null;
        }

        $row = DB::table('pickup_request_shipments as prs')
            ->join('pickup_requests as pr', 'pr.id', '=', 'prs.pickup_request_id')
            ->where('prs.shipment_id', (int) $shipment->id)
            ->whereNull('prs.removed_at')
            ->whereIn('prs.status', self::UNVERIFIED_ITEM_STATUSES)
            ->whereIn('pr.status', PickupStatus::active())
            ->orderByDesc('pr.id')
            ->first(['pr.id', 'pr.request_number']);

        if (! $row) {
            return null;
        }

        return (string) ($row->request_number ?: ('#'.$row->id));
    }

    public static function isAwaitingBranchReceive(object $shipment): bool
    {
        return self::pendingPickupNumber($shipment) !== null;
    }

    /** Message used whenever a not-yet-received pickup parcel is refused. */
    public static function notReceivedMessage(object $shipment, string $pickupNumber): string
    {
        return sprintf(
            '%s is still on pickup %s and has not been received at the branch. Receive it on the pickup first.',
            $shipment->tracking_number ?? ('#'.$shipment->id),
            $pickupNumber
        );
    }

    /**
     * Latest TR line for a parcel (ignoring cancelled/removed lines).
     *
     * @return array{id:int, number:string, status:string, item_status:string, left:bool, from_branch_id:?int, to_branch_id:?int, dispatched_at:?string}|null
     */
    public static function latestTr(int $shipmentId): ?array
    {
        if (! Schema::hasTable('dispatch_manifest_items')) {
            return null;
        }
        $cols = ['dm.id', 'dm.manifest_number', 'dm.status', 'dmi.status as item_status', 'dm.from_branch_id', 'dm.to_branch_id', 'dm.dispatched_at'];
        if (Schema::hasColumn('dispatch_manifests', 'transfer_number')) {
            $cols[] = 'dm.transfer_number';
        }
        $row = DB::table('dispatch_manifest_items as dmi')
            ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
            ->where('dmi.shipment_id', $shipmentId)
            ->whereNotIn('dmi.status', ['cancelled', 'removed'])
            ->where('dm.status', '!=', 'cancelled')
            ->orderByDesc('dmi.id')
            ->first($cols);
        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'number' => (string) (($row->transfer_number ?? null) ?: ($row->manifest_number ?: '#'.$row->id)),
            'status' => (string) $row->status,
            'item_status' => (string) $row->item_status,
            'left' => in_array($row->status, self::LEFT_MANIFEST_STATUSES, true)
                && in_array($row->item_status, self::LEFT_ITEM_STATUSES, true),
            'from_branch_id' => $row->from_branch_id ? (int) $row->from_branch_id : null,
            'to_branch_id' => $row->to_branch_id ? (int) $row->to_branch_id : null,
            'dispatched_at' => $row->dispatched_at ? (string) $row->dispatched_at : null,
        ];
    }

    /**
     * Where a pickup parcel really is, for the pickup verify panel.
     *
     * state:
     *  - not_collected     still with the merchant
     *  - with_rider        picked up, waiting for branch verification (Receive / Reject)
     *  - received          verified at the branch
     *  - rejected          rejected at the branch / failed / cancelled
     *  - left_on_tr        branch never verified it, but it already left on a TR
     *                      (no Receive; "Mark verified" closes the pickup line)
     *  - wrong_in_transit  shows in transit but no TR carried it: status is wrong,
     *                      the branch can still Receive it
     *  - moved_on          further along the network (received elsewhere, out for
     *                      delivery, delivered): "Mark verified" only
     *
     * @return array{state:string,label:string,tr:?array,can_receive:bool,can_reject:bool,needs_sync:bool,resolved:bool}
     */
    public static function describe(object $shipment, ?string $itemStatus): array
    {
        $status = (string) ($shipment->status ?? '');
        $item = (string) ($itemStatus ?? PickupShipmentStatus::PENDING);
        $tr = null;
        $out = static fn (string $state, string $label, bool $receive = false, bool $reject = false, bool $sync = false, bool $resolved = false) => [
            'state' => $state,
            'label' => $label,
            'tr' => null,
            'can_receive' => $receive,
            'can_reject' => $reject,
            'needs_sync' => $sync,
            'resolved' => $resolved,
        ];

        if ($item === PickupShipmentStatus::RECEIVED) {
            $r = $out('received', 'Received at branch', resolved: true);
        } elseif (in_array($item, [PickupShipmentStatus::FAILED, PickupShipmentStatus::REMOVED], true)
            || in_array($status, [CourierStatus::PICKUP_FAILED, CourierStatus::CANCELLED], true)) {
            $r = $out('rejected', $status === CourierStatus::CANCELLED ? 'Cancelled' : 'Rejected', resolved: true,
                sync: ! in_array($item, [PickupShipmentStatus::FAILED, PickupShipmentStatus::REMOVED], true));
        } elseif (in_array($status, [CourierStatus::BOOKED, CourierStatus::AWAITING_PICKUP, CourierStatus::PICKUP_ASSIGNED], true)) {
            $r = $out('not_collected', 'Not collected from merchant yet');
        } elseif ($status === CourierStatus::PICKED_UP) {
            $r = $out('with_rider', 'With rider · waiting for branch receive', receive: true, reject: true);
        } elseif (in_array($status, self::BRANCH_ACCEPTED_STATUSES, true)) {
            $r = $out('received', 'Received at branch', sync: true, resolved: true);
        } else {
            $tr = self::latestTr((int) $shipment->id);
            if ($tr && $tr['left']) {
                $r = $out('left_on_tr', sprintf('Already left on %s (%s) · never verified on this pickup', $tr['number'], str_replace('_', ' ', $tr['status'])), sync: true, resolved: true);
            } elseif (in_array($status, self::IN_TRANSIT_STATUSES, true)) {
                $r = $out('wrong_in_transit', 'Shows "in transit" but no TR carried it · receive it if it is here', receive: true, reject: true);
            } else {
                $r = $out('moved_on', 'Already moved on ('.str_replace('_', ' ', $status).') · never verified on this pickup', sync: true, resolved: true);
            }
        }

        $r['tr'] = $tr ?? (in_array($r['state'], ['received', 'with_rider'], true) ? null : self::latestTr((int) $shipment->id));

        return $r;
    }
}