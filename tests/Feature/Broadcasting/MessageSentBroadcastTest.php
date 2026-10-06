<?php

use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;

uses(RefreshDatabase::class);

test('MessageSent event implements ShouldBroadcastNow and formats broadcast payload', function () {
    $sender = User::factory()->create(['role' => UserRole::User]);
    $receiver = User::factory()->create(['role' => UserRole::User]);

    $message = Message::factory()->create([
        'sender_id' => $sender->id,
        'receiver_id' => $receiver->id,
        'encrypted_payload' => 'payload-base64',
        'iv' => 'iv-base64',
    ]);

    $event = new MessageSent($message);

    expect($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0]->name)->toBe('private-chat.' . $receiver->id)
        ->and($event->broadcastAs())->toBe('MessageSent');

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys(['id', 'sender_id', 'receiver_id', 'encrypted_payload', 'iv', 'created_at'])
        ->and($payload['sender_id'])->toBe($sender->id)
        ->and($payload['receiver_id'])->toBe($receiver->id)
        ->and($payload['encrypted_payload'])->toBe('payload-base64')
        ->and($payload['iv'])->toBe('iv-base64');
});

test('user can authorize listening to their own private chat channel', function () {
    $user = User::factory()->create(['role' => UserRole::User]);

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-chat.' . $user->id,
            'socket_id' => '1234.5678',
        ])
        ->assertOk();
});

test('user cannot authorize listening to another users private chat channel', function () {
    $user = User::factory()->create(['role' => UserRole::User]);
    $otherUser = User::factory()->create(['role' => UserRole::User]);

    $this->actingAs($otherUser)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-chat.' . $user->id,
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});
