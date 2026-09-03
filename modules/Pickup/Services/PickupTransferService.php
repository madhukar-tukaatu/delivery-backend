<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Pickup\Models\PickupRequest;

final class PickupTransferService
{
    /**
     * Transfer a pickup to another active branch.
     *
     * The original shipment origin is NOT changed.
     */
    public function transfer(
        PickupRequest $pickup,
        int $destinationBranchId,
        User $user,
        ?string $reason = null
    ): PickupRequest {

        return DB::transaction(
            function () use (
                $pickup,
                $destinationBranchId,
                $user,
                $reason
            ): PickupRequest {

                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                /*
                |--------------------------------------------------------------------------
                | Only transferable states
                |--------------------------------------------------------------------------
                */

                if (
                    ! in_array(
                        $pickup->status,
                        [
                            'requested',
                            'assigned',
                        ],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'pickup' => [
                            'This pickup cannot be transferred in its current status.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Destination branch
                |--------------------------------------------------------------------------
                */

                $branch = Branch::query()
                    ->whereKey($destinationBranchId)
                    ->where('status', 'active')
                    ->first();

                if (! $branch) {
                    throw ValidationException::withMessages([
                        'branch_id' => [
                            'Selected branch is not active or does not exist.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Prevent same branch transfer
                |--------------------------------------------------------------------------
                */

                if (
                    (int) $pickup->pickup_branch_id
                    ===
                    (int) $branch->id
                ) {
                    throw ValidationException::withMessages([
                        'branch_id' => [
                            'Pickup is already assigned to this branch.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Old branch
                |--------------------------------------------------------------------------
                */

                $oldBranchId =
                    $pickup->pickup_branch_id;

                /*
                |--------------------------------------------------------------------------
                | If a rider was assigned, release the rider
                |--------------------------------------------------------------------------
                */

                $oldAssignedTo =
                    $pickup->assigned_to;

                $pickup->pickup_branch_id =
                    $branch->id;

                $pickup->pickup_sub_branch_id =
                    null;

                /*
                |--------------------------------------------------------------------------
                | Transfer resets assignment
                |--------------------------------------------------------------------------
                */

                $pickup->assigned_to =
                    null;

                $pickup->assigned_by =
                    null;

                $pickup->assigned_at =
                    null;

                /*
                |--------------------------------------------------------------------------
                | Status returns to requested
                |--------------------------------------------------------------------------
                */

                $pickup->status =
                    'requested';

                /*
                |--------------------------------------------------------------------------
                | Transfer remarks
                |--------------------------------------------------------------------------
                */

                $transferReason =
                    $reason
                    ?? 'Pickup transferred to another branch.';

                $pickup->remarks =
                    trim(
                        ($pickup->remarks ?? '')
                        . "\n"
                        . '[TRANSFER] '
                        . $transferReason
                    );

                $pickup->save();

                /*
                |--------------------------------------------------------------------------
                | Pickup event
                |--------------------------------------------------------------------------
                */

                $this->recordEvent(
                    pickup: $pickup,
                    event: 'pickup_transferred',
                    description: sprintf(
                        'Pickup transferred from branch %s to branch %s. Reason: %s',
                        $oldBranchId ?? 'N/A',
                        $branch->id,
                        $transferReason
                    ),
                    userId: $user->id,
                    oldBranchId: $oldBranchId,
                    oldAssignedTo: $oldAssignedTo,
                );

                return $pickup->fresh([
                    'merchant',
                    'pickupLocation',
                    'pickupBranch',
                    'pickupSubBranch',
                    'assignedStaff',
                    'shipments',
                ]);
            }
        );
    }

    /**
     * Record pickup event.
     */
    private function recordEvent(
        PickupRequest $pickup,
        string $event,
        string $description,
        ?int $userId,
        ?int $oldBranchId = null,
        ?int $oldAssignedTo = null
    ): void {

        if (
            ! DB::getSchemaBuilder()
                ->hasTable('pickup_events')
        ) {
            return;
        }

        $columns =
            DB::getSchemaBuilder()
                ->getColumnListing(
                    'pickup_events'
                );

        $data = [

            'pickup_request_id' =>
                $pickup->id,

            'event' =>
                $event,

            'status' =>
                $pickup->status,

            'description' =>
                $description,

            'branch_id' =>
                $pickup->pickup_branch_id,

            'sub_branch_id' =>
                $pickup->pickup_sub_branch_id,

            'old_branch_id' =>
                $oldBranchId,

            'old_assigned_to' =>
                $oldAssignedTo,

            'user_id' =>
                $userId,

            'created_at' =>
                now(),

            'updated_at' =>
                now(),
        ];

        $data =
            array_intersect_key(
                $data,
                array_flip($columns)
            );

        DB::table('pickup_events')
            ->insert($data);
    }
}