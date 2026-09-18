<?php

namespace Modules\Merchant\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantChangeRequest;
use App\Models\User;

/**
 * Unified event for all merchant change request events.
 * 
 * Supports three action types:
 * - 'requested' - Merchant submitted a change request
 * - 'approved' - Admin approved the change request
 * - 'rejected' - Admin rejected the change request
 */
class MerchantChangeEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public MerchantChangeRequest $request,
        public Merchant $merchant,
        public User $performedByUser,
        public string $action = 'requested', // 'requested', 'approved', 'rejected'
    ) {
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('admin.merchant-changes'),
            new PrivateChannel("admin.merchant.{$this->merchant->id}"),
        ];

        // Include merchant channel for approved/rejected actions
        if (in_array($this->action, ['approved', 'rejected'])) {
            $channels[] = new PrivateChannel("merchant.{$this->merchant->id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return "merchant.change.{$this->action}";
    }

    public function broadcastWith(): array
    {
        $data = [
            'request_id' => $this->request->id,
            'merchant_id' => $this->merchant->id,
            'merchant_name' => $this->merchant->name,
            'change_type' => $this->request->change_type,
            'affected_services' => $this->request->affected_services,
            'action' => $this->action,
            'performed_by' => $this->performedByUser->name,
        ];

        // Add action-specific data
        switch ($this->action) {
            case 'requested':
                $data['reason'] = $this->request->reason;
                $data['change_summary'] = $this->request->getChangeSummary();
                $data['requested_at'] = $this->request->requested_at->toISOString();
                break;

            case 'approved':
                $data['services_now_active'] = true;
                $data['decision_at'] = $this->request->decision_at->toISOString();
                break;

            case 'rejected':
                $data['rejection_reason'] = $this->request->rejection_reason;
                $data['services_now_active'] = true;
                $data['decision_at'] = $this->request->decision_at->toISOString();
                break;
        }

        return $data;
    }
}
