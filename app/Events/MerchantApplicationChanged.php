<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class MerchantApplicationChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly array $merchant,
        public readonly string $action,
        public readonly ?int $performedBy = null,
    ) {
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('admin.merchants'),
            new PrivateChannel('admin.operations'),
            new PrivateChannel('admin.merchant-applications'),
        ];

        if (!empty($this->merchant['id'])) {
            $channels[] = new PrivateChannel(
                'merchant.' . $this->merchant['id']
            );
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'merchant.application.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'merchant' => $this->merchant,
            'performed_by' => $this->performedBy,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
