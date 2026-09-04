<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Merchant\Models\Merchant;
use Modules\Pickup\Jobs\SendPickupCallback;
use Modules\Pickup\Models\PickupRequest;
use Modules\Shipment\Models\Shipment;

/**
 * Builds pickup-lifecycle callback payloads and dispatches them to the
 * store partner via SendPickupCallback.
 *
 * Every dispatch is queued afterCommit() so the callback only fires once
 * the surrounding DB transaction (in PickupRequestService) has committed.
 *
 * Callbacks are best-effort: a missing callback URL is handled inside the
 * job, and a failed HTTP call is retried by the job, never breaking the
 * pickup workflow itself.
 */
final class PickupCallbackService
{
    /*
    |--------------------------------------------------------------------------
    | pickup.rider_assigned
    |--------------------------------------------------------------------------
    */
    public function riderAssigned(PickupRequest $pickup): void
    {
        $this->dispatchPickupEvent(
            pickup: $pickup,
            event: 'pickup.rider_assigned',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | pickup.rider_started
    |--------------------------------------------------------------------------
    */
    public function riderStarted(PickupRequest $pickup): void
    {
        $this->dispatchPickupEvent(
            pickup: $pickup,
            event: 'pickup.rider_started',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | pickup.rider_arrived
    |--------------------------------------------------------------------------
    */
    public function riderArrived(PickupRequest $pickup): void
    {
        $this->dispatchPickupEvent(
            pickup: $pickup,
            event: 'pickup.rider_arrived',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | shipment.collected
    |--------------------------------------------------------------------------
    */
    public function shipmentCollected(
        PickupRequest $pickup,
        Shipment $shipment,
    ): void {
        $merchantId = (int) $pickup->merchant_id;

        if ($merchantId <= 0) {
            return;
        }

        $payload = [
            'event_id' => $this->eventId('shipment_collected'),
            'event' => 'shipment.collected',
            'occurred_at' => $this->now(),
            'merchant_id' => $merchantId,
            'pickup' => [
                'request_number' => $pickup->request_number,
                'status' => $pickup->status,
            ],
            'shipment' => [
                'id' => (int) $shipment->id,
                'tracking_number' => $shipment->tracking_number,
                'merchant_order_id' => $shipment->merchant_order_id,
                'status' => $shipment->status,
            ],
        ];

        $this->dispatch($merchantId, $payload);
    }

    /*
    |--------------------------------------------------------------------------
    | pickup.completed
    |--------------------------------------------------------------------------
    */
    public function pickupCompleted(PickupRequest $pickup): void
    {
        $merchantId = (int) $pickup->merchant_id;

        if ($merchantId <= 0) {
            return;
        }

        $shipments = $pickup
            ->activeShipments()
            ->get()
            ->map(static function (Shipment $shipment): array {
                return [
                    'id' => (int) $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'merchant_order_id' => $shipment->merchant_order_id,
                    'status' => $shipment->status,
                ];
            })
            ->values()
            ->all();

        $payload = [
            'event_id' => $this->eventId('pickup_completed'),
            'event' => 'pickup.completed',
            'occurred_at' => $this->now(),
            'merchant_id' => $merchantId,
            'pickup' => [
                'id' => (int) $pickup->id,
                'request_number' => $pickup->request_number,
                'store_reference' => $pickup->store_reference,
                'status' => $pickup->status,
                'pickup_location_id' => $pickup->pickup_location_id !== null
                    ? (int) $pickup->pickup_location_id
                    : null,
                'completed_at' => optional($pickup->completed_at)
                    ->toIso8601String(),
            ],
            'shipments' => $shipments,
        ];

        $this->dispatch($merchantId, $payload);
    }

    /*
    |--------------------------------------------------------------------------
    | shipment.received_at_origin
    |--------------------------------------------------------------------------
    */
    public function shipmentReceivedAtOrigin(
        PickupRequest $pickup,
        Shipment $shipment,
    ): void {
        $merchantId = (int) $pickup->merchant_id;

        if ($merchantId <= 0) {
            return;
        }

        $payload = [
            'event_id' => $this->eventId('shipment_received_origin'),
            'event' => 'shipment.received_at_origin',
            'occurred_at' => $this->now(),
            'merchant_id' => $merchantId,
            'pickup' => [
                'request_number' => $pickup->request_number,
                'status' => $pickup->status,
            ],
            'shipment' => [
                'id' => (int) $shipment->id,
                'tracking_number' => $shipment->tracking_number,
                'merchant_order_id' => $shipment->merchant_order_id,
                'status' => $shipment->status,
                'origin_branch_id' => $shipment->origin_branch_id !== null
                    ? (int) $shipment->origin_branch_id
                    : null,
                'origin_sub_branch_id' => $shipment->origin_sub_branch_id !== null
                    ? (int) $shipment->origin_sub_branch_id
                    : null,
            ],
        ];

        $this->dispatch($merchantId, $payload);
    }

    /*
    |--------------------------------------------------------------------------
    | Manual re-send
    |
    | Rebuilds and re-dispatches a callback for a given pickup + event using
    | the pickup's CURRENT state. Used by the admin "resend callback" action
    | to replay an event to the store partner (e.g. after a delivery failure
    | or a 422 that has since been fixed).
    |
    | Shipment-scoped events (shipment.collected / shipment.received_at_origin)
    | require a shipment; the caller must pass one for those events.
    |--------------------------------------------------------------------------
    */
    public const RESENDABLE_EVENTS = [
        'pickup.rider_assigned',
        'pickup.rider_started',
        'pickup.rider_arrived',
        'pickup.completed',
        'shipment.collected',
        'shipment.received_at_origin',
    ];

    public function resend(
        PickupRequest $pickup,
        string $event,
        ?Shipment $shipment = null,
    ): void {
        switch ($event) {
            case 'pickup.rider_assigned':
                $this->riderAssigned($pickup);
                break;

            case 'pickup.rider_started':
                $this->riderStarted($pickup);
                break;

            case 'pickup.rider_arrived':
                $this->riderArrived($pickup);
                break;

            case 'pickup.completed':
                $this->pickupCompleted($pickup);
                break;

            case 'shipment.collected':
                if ($shipment === null) {
                    throw new \InvalidArgumentException(
                        'A shipment is required to resend shipment.collected.'
                    );
                }
                $this->shipmentCollected($pickup, $shipment);
                break;

            case 'shipment.received_at_origin':
                if ($shipment === null) {
                    throw new \InvalidArgumentException(
                        'A shipment is required to resend shipment.received_at_origin.'
                    );
                }
                $this->shipmentReceivedAtOrigin($pickup, $shipment);
                break;

            default:
                throw new \InvalidArgumentException(
                    'Unsupported pickup callback event: ' . $event
                );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Shared builder for the pickup-level rider events
    | (rider_assigned / rider_started / rider_arrived)
    |--------------------------------------------------------------------------
    */
    private function dispatchPickupEvent(
        PickupRequest $pickup,
        string $event,
    ): void {
        $merchantId = (int) $pickup->merchant_id;

        if ($merchantId <= 0) {
            return;
        }

        $payload = [
            'event_id' => $this->eventId($event),
            'event' => $event,
            'occurred_at' => $this->now(),
            'merchant_id' => $merchantId,
            'pickup' => [
                'id' => (int) $pickup->id,
                'request_number' => $pickup->request_number,
                'store_reference' => $pickup->store_reference,
                'status' => $pickup->status,
                'pickup_location_id' => $pickup->pickup_location_id !== null
                    ? (int) $pickup->pickup_location_id
                    : null,
                'rider' => $this->riderPayload($pickup),
            ],
        ];

        $this->dispatch($merchantId, $payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function riderPayload(PickupRequest $pickup): ?array
    {
        $rider = $pickup->assignedStaff
            ?? ($pickup->assigned_to
                ? \App\Models\User::query()->find($pickup->assigned_to)
                : null);

        if (! $rider) {
            return null;
        }

        return [
            'id' => (int) $rider->id,
            'name' => $rider->name,
            'phone' => $rider->phone,
        ];
    }

    /**
     * Queue the delivery job for a pickup callback event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(int $merchantId, array $payload): void
    {
        $callbackUrl = trim(
            (string) Merchant::query()
                ->whereKey($merchantId)
                ->value('integration_callback_url')
        );

        Log::info('Pickup callback queued.', [
            'merchant_id' => $merchantId,
            'event' => $payload['event'] ?? null,
            'event_id' => $payload['event_id'] ?? null,
            'has_callback_url' => $callbackUrl !== '',
            /*
             * Full event payload (before the job prepends
             * application_number / merchant_reference). Logged so every
             * pickup callback event can be inspected in storage/logs.
             */
            'payload' => $payload,
        ]);

        SendPickupCallback::dispatch($merchantId, $payload)
            ->afterCommit()
            ->onQueue('webhooks');
    }

    private function eventId(string $prefix): string
    {
        $clean = str_replace('.', '_', $prefix);

        return 'evt_' . $clean . '_' . Str::lower(Str::random(24));
    }

    private function now(): string
    {
        return now()->toIso8601String();
    }
}
