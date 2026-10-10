<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->message->receiver_id)];
    }

    public function broadcastAs(): string
    {
        return 'MessageSent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $sender = $this->message->sender;

        return [
            'id' => $this->message->id,
            'sender_id' => $this->message->sender_id,
            'receiver_id' => $this->message->receiver_id,
            'sender_key_version' => $this->message->sender_key_version,
            'receiver_key_version' => $this->message->receiver_key_version,
            'encrypted_payload' => $this->message->encrypted_payload,
            'iv' => $this->message->iv,
            'created_at' => $this->message->created_at->toISOString(),
            'sender' => [
                'id' => $sender->id,
                'name' => $sender->name,
                'email' => $sender->email,
                'public_key' => $sender->public_key,
                'public_key_version' => $sender->public_key_version,
            ],
        ];
    }
}
