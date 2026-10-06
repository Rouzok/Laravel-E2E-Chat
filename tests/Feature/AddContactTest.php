<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('add contact component renders', function () {
    $user = User::factory()->create(['role' => UserRole::User]);

    Livewire::actingAs($user)
        ->test('add-contact')
        ->assertOk();
});

test('user can add a valid contact by email', function () {
    $user = User::factory()->create(['role' => UserRole::User]);
    $contact = User::factory()->create(['role' => UserRole::User]);

    Livewire::actingAs($user)
        ->test('add-contact')
        ->set('email', $contact->email)
        ->call('addContact')
        ->assertHasNoErrors();

    expect($user->contacts()->where('contact_id', $contact->id)->exists())->toBeTrue();
});

test('add contact dispatches contact-added event on success', function () {
    $user = User::factory()->create(['role' => UserRole::User]);
    $contact = User::factory()->create(['role' => UserRole::User]);

    Livewire::actingAs($user)
        ->test('add-contact')
        ->set('email', $contact->email)
        ->call('addContact')
        ->assertDispatched('contact-added');
});

test('add contact fails with invalid email format', function () {
    $user = User::factory()->create(['role' => UserRole::User]);

    Livewire::actingAs($user)
        ->test('add-contact')
        ->set('email', 'not-an-email')
        ->call('addContact')
        ->assertHasErrors(['email']);
});

test('add contact fails when no user exists with that email', function () {
    $user = User::factory()->create(['role' => UserRole::User]);

    Livewire::actingAs($user)
        ->test('add-contact')
        ->set('email', 'nonexistent@example.com')
        ->call('addContact')
        ->assertHasErrors(['email']);

    expect($user->contacts()->count())->toBe(0);
});

test('user cannot add themselves as a contact', function () {
    $user = User::factory()->create(['role' => UserRole::User]);

    Livewire::actingAs($user)
        ->test('add-contact')
        ->set('email', $user->email)
        ->call('addContact')
        ->assertHasErrors(['email']);

    expect($user->contacts()->count())->toBe(0);
});

test('user cannot add a contact that is already in their list', function () {
    $user = User::factory()->create(['role' => UserRole::User]);
    $contact = User::factory()->create(['role' => UserRole::User]);
    $user->contacts()->attach($contact->id);

    Livewire::actingAs($user)
        ->test('add-contact')
        ->set('email', $contact->email)
        ->call('addContact')
        ->assertHasErrors(['email']);

    expect($user->contacts()->count())->toBe(1);
});
