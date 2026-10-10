<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BlockStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $blockerId,
        public readonly int $blockedId,
        public readonly bool $blocked,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.'.$this->blockerId),
            new PrivateChannel('chat.'.$this->blockedId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'BlockStatusUpdated';
    }

    /**
     * @return array<string, bool|int>
     */
    public function broadcastWith(): array
    {
        return [
            'blocker_id' => $this->blockerId,
            'blocked_id' => $this->blockedId,
            'blocked' => $this->blocked,
        ];
    }
}
