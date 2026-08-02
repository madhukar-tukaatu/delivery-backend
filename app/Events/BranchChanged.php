<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class BranchChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly array $branch,
        public readonly string $action,
        public readonly ?int $performedBy = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.branches'),
            new PrivateChannel('admin.operations'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'branch.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'branch' => $this->branch,
            'performed_by' => $this->performedBy,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}