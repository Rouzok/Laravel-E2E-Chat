<?php

use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('authenticated user can store public key', function () {
    $user = User::factory()->create(['role' => UserRole::User]);

    $this->actingAs($user)
        ->putJson(route('user.public-key.store'), [
            'public_key' => '{"kty":"EC","crv":"P-256","x":"test","y":"test"}',
        ])
        ->assertOk()
        ->assertJson(['message' => 'Public key updated.']);

    expect($user->fresh()->public_key)->toBe('{"kty":"EC","crv":"P-256","x":"test","y":"test"}');
});

test('authenticated user can fetch another user public key', function () {
    $user1 = User::factory()->create(['role' => UserRole::User]);
    $user2 = User::factory()->create([
        'role' => UserRole::User,
        'public_key' => '{"kty":"EC","crv":"P-256","x":"user2_x","y":"user2_y"}',
    ]);

    $this->actingAs($user1)
        ->getJson(route('user.public-key', $user2))
        ->assertOk()
        ->assertJson(['public_key' => '{"kty":"EC","crv":"P-256","x":"user2_x","y":"user2_y"}']);
});

test('authenticated user can store encrypted message and dispatch MessageSent event', function () {
    Event::fake([MessageSent::class]);

    $sender = User::factory()->create(['role' => UserRole::User]);
    $receiver = User::factory()->create(['role' => UserRole::User]);

    $response = $this->actingAs($sender)
        ->postJson(route('messages.store'), [
            'receiver_id' => $receiver->id,
            'encrypted_payload' => 'encrypted-ciphertext-base64',
            'iv' => 'iv-base64',
        ])
        ->assertCreated()
        ->assertJsonFragment([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'encrypted_payload' => 'encrypted-ciphertext-base64',
            'iv' => 'iv-base64',
        ]);

    $this->assertDatabaseHas('messages', [
        'sender_id' => $sender->id,
        'receiver_id' => $receiver->id,
        'encrypted_payload' => 'encrypted-ciphertext-base64',
        'iv' => 'iv-base64',
    ]);

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($receiver) {
        return $event->message->receiver_id === $receiver->id
            && $event->broadcastOn()[0]->name === 'private-chat.'.$receiver->id;
    });
});

test('authenticated user can fetch chat history between two users in chronological order', function () {
    $user1 = User::factory()->create(['role' => UserRole::User]);
    $user2 = User::factory()->create(['role' => UserRole::User]);

    $msg1 = Message::factory()->create([
        'sender_id' => $user1->id,
        'receiver_id' => $user2->id,
        'created_at' => now()->subMinutes(10),
    ]);

    $msg2 = Message::factory()->create([
        'sender_id' => $user2->id,
        'receiver_id' => $user1->id,
        'created_at' => now()->subMinutes(5),
    ]);

    $response = $this->actingAs($user1)
        ->getJson(route('messages.history', $user2))
        ->assertOk();

    $messages = $response->json();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]['id'])->toBe($msg1->id)
        ->and($messages[1]['id'])->toBe($msg2->id);
});

test('admin can synchronize encryption keys and send encrypted messages', function () {
    Event::fake([MessageSent::class]);

    $admin = User::factory()->admin()->create();
    $contact = User::factory()->create([
        'role' => UserRole::User,
        'public_key' => '{"kty":"EC","crv":"P-256","x":"contact_x","y":"contact_y"}',
    ]);
    $publicKey = '{"kty":"EC","crv":"P-256","x":"admin_x","y":"admin_y"}';

    $this->actingAs($admin)
        ->putJson(route('user.public-key.store'), ['public_key' => $publicKey])
        ->assertOk();

    $this->getJson(route('user.public-key', $contact))
        ->assertOk()
        ->assertJson(['public_key' => $contact->public_key]);

    $this->postJson(route('messages.store'), [
        'receiver_id' => $contact->id,
        'encrypted_payload' => 'ciphertext-base64',
        'iv' => 'iv-base64',
    ])->assertCreated();

    $this->getJson(route('messages.history', $contact))
        ->assertOk()
        ->assertJsonPath('0.encrypted_payload', 'ciphertext-base64');

    expect($admin->fresh()->public_key)->toBe($publicKey);
    $this->assertDatabaseHas('messages', [
        'sender_id' => $admin->id,
        'receiver_id' => $contact->id,
        'encrypted_payload' => 'ciphertext-base64',
        'iv' => 'iv-base64',
    ]);
    Event::assertDispatched(MessageSent::class);
});

test('guests receive 401 when sending encrypted messages', function () {
    $contact = User::factory()->create(['role' => UserRole::User]);

    $this->postJson(route('messages.store'), [
        'receiver_id' => $contact->id,
        'encrypted_payload' => 'ciphertext-base64',
        'iv' => 'iv-base64',
    ])->assertUnauthorized();
});

test('authenticated regular user can visit chat page', function () {
    $user = User::factory()->create(['role' => UserRole::User]);
    $contact = User::factory()->create(['role' => UserRole::User]);
    $user->contacts()->attach($contact->id);

    $this->actingAs($user)
        ->get(route('chat'))
        ->assertOk()
        ->assertSee($contact->email);
});
