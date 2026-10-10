<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CleanupUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $firstUserId,
        public readonly int $secondUserId,
        public readonly string $status,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.'.$this->firstUserId),
            new PrivateChannel('chat.'.$this->secondUserId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'CleanupUpdated';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'first_user_id' => $this->firstUserId,
            'second_user_id' => $this->secondUserId,
            'status' => $this->status,
        ];
    }
}
