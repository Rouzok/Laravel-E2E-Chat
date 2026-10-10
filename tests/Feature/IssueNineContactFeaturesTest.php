<?php

use App\Enums\UserRole;
use App\Events\BlockStatusUpdated;
use App\Events\CleanupUpdated;
use App\Events\MessageSent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('contacts can have private custom names and blank labels fall back to email', function () {
    $owner = User::factory()->create(['role' => UserRole::User]);
    $otherOwner = User::factory()->create(['role' => UserRole::User]);
    $contact = User::factory()->create(['role' => UserRole::User, 'name' => 'Account Name']);

    Livewire::actingAs($owner)
        ->test('add-contact')
        ->set('email', $contact->email)
        ->set('customName', ' Friend ')
        ->call('addContact')
        ->assertHasNoErrors();

    Livewire::actingAs($otherOwner)
        ->test('add-contact')
        ->set('email', $contact->email)
        ->set('customName', '   ')
        ->call('addContact')
        ->assertHasNoErrors();

    expect($owner->contacts()->first()->pivot->custom_name)->toBe('Friend')
        ->and($otherOwner->contacts()->first()->pivot->custom_name)->toBeNull()
        ->and($owner->contacts()->first()->name)->toBe('Account Name')
        ->and($otherOwner->contacts()->first()->email)->toBe($contact->email);

    $this->actingAs($owner)->get(route('chat'))->assertOk()->assertSee('Friend');
    $this->actingAs($otherOwner)->get(route('chat'))->assertOk()->assertSee($contact->email);
});

test('adding a contact validates custom name length and resets both fields when closed', function () {
    $owner = User::factory()->create(['role' => UserRole::User]);
    $contact = User::factory()->create(['role' => UserRole::User]);

    Livewire::actingAs($owner)
        ->test('add-contact')
        ->call('openModal')
        ->set('email', $contact->email)
        ->set('customName', str_repeat('a', 81))
        ->call('addContact')
        ->assertHasErrors(['customName' => 'max']);

    Livewire::actingAs($owner)
        ->test('add-contact')
        ->call('openModal')
        ->set('email', $contact->email)
        ->set('customName', 'Friend')
        ->call('closeModal')
        ->assertSet('email', '')
        ->assertSet('customName', null);
});

test('sending a message creates one reverse contact and preserves its private label', function () {
    Event::fake([MessageSent::class]);

    $sender = User::factory()->create(['role' => UserRole::User]);
    $receiver = User::factory()->create(['role' => UserRole::User]);
    $sender->contacts()->attach($receiver->id);

    $this->actingAs($sender)->postJson(route('messages.store'), [
        'receiver_id' => $receiver->id,
        'encrypted_payload' => 'ciphertext-one',
        'iv' => 'iv-one',
    ])->assertCreated();

    expect($receiver->fresh()->contacts()->whereKey($sender->id)->exists())->toBeTrue();
    expect($receiver->fresh()->contacts()->first()->pivot->custom_name)->toBeNull();

    $this->actingAs($receiver)->postJson(route('messages.store'), [
        'receiver_id' => $sender->id,
        'encrypted_payload' => 'reply-ciphertext',
        'iv' => 'reply-iv',
    ])->assertCreated();

    $receiver->contacts()->updateExistingPivot($sender->id, ['custom_name' => 'My friend']);

    $this->actingAs($sender)->postJson(route('messages.store'), [
        'receiver_id' => $receiver->id,
        'encrypted_payload' => 'ciphertext-two',
        'iv' => 'iv-two',
    ])->assertCreated();

    expect($receiver->fresh()->contacts()->whereKey($sender->id)->count())->toBe(1)
        ->and(Message::query()->count())->toBe(3)
        ->and($receiver->fresh()->contacts()->first()->pivot->custom_name)->toBe('My friend');
});

test('message broadcasts include sender details so an online recipient can list a new contact', function () {
    $sender = User::factory()->create(['role' => UserRole::User]);
    $receiver = User::factory()->create(['role' => UserRole::User]);
    $message = Message::factory()->create([
        'sender_id' => $sender->id,
        'receiver_id' => $receiver->id,
    ]);

    expect((new MessageSent($message))->broadcastWith()['sender'])
        ->toMatchArray(['id' => $sender->id, 'name' => $sender->name, 'email' => $sender->email]);
});

test('blocking in either direction rejects messages and does not create a reverse contact', function () {
    $sender = User::factory()->create(['role' => UserRole::User]);
    $receiver = User::factory()->create(['role' => UserRole::User]);
    $sender->contacts()->attach($receiver->id);
    $receiver->blockedUsers()->attach($sender->id);

    $this->actingAs($sender)->postJson(route('messages.store'), [
        'receiver_id' => $receiver->id,
        'encrypted_payload' => 'blocked-ciphertext',
        'iv' => 'blocked-iv',
    ])->assertForbidden();

    expect(Message::query()->count())->toBe(0)
        ->and($receiver->contacts()->whereKey($sender->id)->exists())->toBeFalse();

    $receiver->blockedUsers()->detach($sender->id);
    $sender->blockedUsers()->attach($receiver->id);

    $this->actingAs($receiver)->postJson(route('messages.store'), [
        'receiver_id' => $sender->id,
        'encrypted_payload' => 'blocked-ciphertext',
        'iv' => 'blocked-iv',
    ])->assertForbidden();
});

test('unblocking restores messages only after both participants remove their own blocks', function () {
    Event::fake([BlockStatusUpdated::class, MessageSent::class]);

    $first = User::factory()->create(['role' => UserRole::User]);
    $second = User::factory()->create(['role' => UserRole::User]);
    $first->contacts()->attach($second->id);
    $second->contacts()->attach($first->id);
    $first->blockedUsers()->attach($second->id);
    $second->blockedUsers()->attach($first->id);

    Livewire::actingAs($first)
        ->test('pages::contact-profile', ['user' => $second])
        ->call('unblockUser');

    $this->actingAs($first)->postJson(route('messages.store'), [
        'receiver_id' => $second->id,
        'encrypted_payload' => 'still-blocked-ciphertext',
        'iv' => 'still-blocked-iv',
    ])->assertForbidden();

    Livewire::actingAs($second)
        ->test('pages::contact-profile', ['user' => $first])
        ->call('unblockUser');

    $this->actingAs($first)->postJson(route('messages.store'), [
        'receiver_id' => $second->id,
        'encrypted_payload' => 'unblocked-ciphertext',
        'iv' => 'unblocked-iv',
    ])->assertCreated();
});

test('contact owner can rename a contact and clear the name while other owners remain unaffected', function () {
    $owner = User::factory()->create(['role' => UserRole::User]);
    $otherOwner = User::factory()->create(['role' => UserRole::User]);
    $contact = User::factory()->create(['role' => UserRole::User]);
    $owner->contacts()->attach($contact->id);
    $otherOwner->contacts()->attach($contact->id, ['custom_name' => 'Work']);

    Livewire::actingAs($owner)
        ->test('pages::contact-profile', ['user' => $contact])
        ->set('customName', 'Bestie')
        ->call('saveCustomName')
        ->assertHasNoErrors();

    expect($owner->fresh()->contacts()->first()->pivot->custom_name)->toBe('Bestie')
        ->and($otherOwner->fresh()->contacts()->first()->pivot->custom_name)->toBe('Work');
    $this->actingAs($owner)->get(route('chat'))->assertSee('Bestie');

    Livewire::actingAs($owner)
        ->test('pages::contact-profile', ['user' => $contact])
        ->set('customName', '   ')
        ->call('saveCustomName')
        ->assertHasNoErrors();

    expect($owner->fresh()->contacts()->first()->pivot->custom_name)->toBeNull();
    $this->actingAs($owner)->get(route('chat'))->assertSee($contact->email);
});

test('cleanup requires the other participant and deletes only messages covered by approval', function () {
    Event::fake([CleanupUpdated::class]);

    $requester = User::factory()->create(['role' => UserRole::User]);
    $other = User::factory()->create(['role' => UserRole::User]);
    $requester->contacts()->attach($other->id);
    $other->contacts()->attach($requester->id);
    $coveredMessage = Message::factory()->create(['sender_id' => $requester->id, 'receiver_id' => $other->id]);

    Livewire::actingAs($requester)
        ->test('pages::contact-profile', ['user' => $other])
        ->call('requestCleanup');

    $request = DB::table('cleanup_requests')->first();
    $laterMessage = Message::factory()->create(['sender_id' => $other->id, 'receiver_id' => $requester->id]);

    expect(Message::query()->whereKey($coveredMessage->id)->exists())->toBeTrue();

    Livewire::actingAs($other)
        ->test('pages::contact-profile', ['user' => $requester])
        ->call('approveCleanup', $request->id);

    expect(Message::query()->whereKey($coveredMessage->id)->exists())->toBeFalse()
        ->and(Message::query()->whereKey($laterMessage->id)->exists())->toBeTrue()
        ->and(DB::table('cleanup_requests')->where('id', $request->id)->value('status'))->toBe('approved');

    Event::assertDispatched(CleanupUpdated::class);
});

test('declining or cancelling a cleanup request preserves messages', function () {
    Event::fake([CleanupUpdated::class]);

    $requester = User::factory()->create(['role' => UserRole::User]);
    $other = User::factory()->create(['role' => UserRole::User]);
    $requester->contacts()->attach($other->id);
    $other->contacts()->attach($requester->id);
    $message = Message::factory()->create(['sender_id' => $requester->id, 'receiver_id' => $other->id]);

    Livewire::actingAs($requester)
        ->test('pages::contact-profile', ['user' => $other])
        ->call('requestCleanup');
    $declinedRequestId = DB::table('cleanup_requests')->value('id');

    Livewire::actingAs($other)
        ->test('pages::contact-profile', ['user' => $requester])
        ->call('declineCleanup', $declinedRequestId);

    expect(Message::query()->whereKey($message->id)->exists())->toBeTrue();

    Livewire::actingAs($requester)
        ->test('pages::contact-profile', ['user' => $other])
        ->call('requestCleanup');
    $cancelledRequestId = DB::table('cleanup_requests')->latest('id')->value('id');

    Livewire::actingAs($requester)
        ->test('pages::contact-profile', ['user' => $other])
        ->call('cancelCleanup', $cancelledRequestId);

    expect(Message::query()->whereKey($message->id)->exists())->toBeTrue()
        ->and(DB::table('cleanup_requests')->where('id', $cancelledRequestId)->value('status'))->toBe('cancelled');
});

test('a participant cannot approve a cleanup request from a different contact profile', function () {
    Event::fake([CleanupUpdated::class]);

    $requester = User::factory()->create(['role' => UserRole::User]);
    $participant = User::factory()->create(['role' => UserRole::User]);
    $otherContact = User::factory()->create(['role' => UserRole::User]);
    $requester->contacts()->attach($participant->id);
    $participant->contacts()->attach([$requester->id, $otherContact->id]);

    Livewire::actingAs($requester)
        ->test('pages::contact-profile', ['user' => $participant])
        ->call('requestCleanup');
    $requestId = DB::table('cleanup_requests')->value('id');

    Livewire::actingAs($participant)
        ->test('pages::contact-profile', ['user' => $otherContact])
        ->call('approveCleanup', $requestId)
        ->assertForbidden();
});

test('reports are private from the reported user and admins can review them', function () {
    $reporter = User::factory()->create(['role' => UserRole::User]);
    $reported = User::factory()->create(['role' => UserRole::User]);
    $reporter->contacts()->attach($reported->id);
    $reported->contacts()->attach($reporter->id);

    Livewire::actingAs($reporter)
        ->test('pages::contact-profile', ['user' => $reported])
        ->set('reportReason', 'Harassment')
        ->set('reportDetails', 'Repeated unwanted messages')
        ->call('reportUser')
        ->assertSet('reportSubmitted', true)
        ->assertHasNoErrors();

    $reportId = DB::table('user_reports')->value('id');
    expect(DB::table('user_reports')->where('id', $reportId)->value('status'))->toBe('pending');

    $this->actingAs($reported)->get(route('contacts.show', $reporter))->assertOk()->assertDontSee('Repeated unwanted messages');
    $this->actingAs($reporter)->get(route('admin.reports'))->assertRedirect(route('dashboard'));

    $admin = User::factory()->admin()->create();
    Livewire::actingAs($admin)
        ->test('pages::admin-reports')
        ->assertSee('Repeated unwanted messages')
        ->call('updateStatus', $reportId, 'reviewed');

    expect(DB::table('user_reports')->where('id', $reportId)->value('status'))->toBe('reviewed');
});

test('users cannot open or manipulate another users contact profile', function () {
    $contact = User::factory()->create(['role' => UserRole::User]);
    $outsider = User::factory()->create(['role' => UserRole::User]);

    $this->actingAs($outsider)->get(route('contacts.show', $contact))->assertNotFound();

    Livewire::actingAs($outsider)
        ->test('pages::contact-profile', ['user' => $contact])
        ->assertNotFound();
});
