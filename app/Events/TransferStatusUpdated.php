<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransferStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public array $transferData = [],
        public string $action = 'updated',
        public ?int $branchId = null,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('admin.dashboard'),
            new PrivateChannel('admin.transfers'),
        ];

        if ($this->branchId) {
            $channels[] = new PrivateChannel("branch.{$this->branchId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'transfer.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'transfer' => $this->transferData,
            'branch_id' => $this->branchId,
            'updated_at' => now()->toISOString(),
        ];
    }
}
