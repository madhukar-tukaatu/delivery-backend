<?php

namespace Modules\Merchant\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Merchant\Enums\MerchantStatus;

class MerchantApplicationChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public int $merchantId;

    public string $action;

    public ?string $source = null;

    public ?string $status = null;

    public function __construct(
        int $merchantId,
        string $action,
        ?string $source = null,
        MerchantStatus|string|null $status = null
    ) {
        $this->merchantId = $merchantId;
        $this->action = $action;
        $this->source = $source;
        $this->status = $status instanceof MerchantStatus
            ? $status->value
            : $status;
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
