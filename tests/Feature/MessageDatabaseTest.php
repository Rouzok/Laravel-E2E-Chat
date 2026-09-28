<?php

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user public key can be updated and persisted', function () {
    $user = User::factory()->create([
        'public_key' => 'sample-ecdh-public-key-base64',
    ]);

    expect($user->public_key)->toBe('sample-ecdh-public-key-base64');
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'public_key' => 'sample-ecdh-public-key-base64',
    ]);
});

test('messages can be created and retrieved with relationships', function () {
    $sender = User::factory()->create();
    $receiver = User::factory()->create();

    $message = Message::factory()->create([
        'sender_id' => $sender->id,
        'receiver_id' => $receiver->id,
        'encrypted_payload' => 'ciphertext-base64-data',
        'iv' => 'iv-base64-data',
    ]);

    expect($message->sender->id)->toBe($sender->id)
        ->and($message->receiver->id)->toBe($receiver->id)
        ->and($message->encrypted_payload)->toBe('ciphertext-base64-data')
        ->and($message->iv)->toBe('iv-base64-data');

    expect($sender->sentMessages)->toHaveCount(1)
        ->and($sender->sentMessages->first()->id)->toBe($message->id);

    expect($receiver->receivedMessages)->toHaveCount(1)
        ->and($receiver->receivedMessages->first()->id)->toBe($message->id);
});
