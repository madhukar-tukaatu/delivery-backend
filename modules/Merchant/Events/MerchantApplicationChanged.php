<?php

namespace Modules\Merchant\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MerchantApplicationChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $merchantId,
        public string $action,
        public ?string $source = null,
        public ?string $status = null
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.merchant-applications'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'merchant.application.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'merchant_id' => $this->merchantId,
            'action' => $this->action,
            'source' => $this->source,
            'status' => $this->status,
            'occurred_at' => now()->toISOString(),
        ];
    }
}