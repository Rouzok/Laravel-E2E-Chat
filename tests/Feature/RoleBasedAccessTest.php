<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to login when accessing dashboard or admin dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

test('regular users can access user dashboard', function () {
    $user = User::factory()->create(['role' => UserRole::User]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('regular users are redirected to user dashboard when attempting to access admin dashboard', function () {
    $user = User::factory()->create(['role' => UserRole::User]);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('dashboard'));
});

test('admin users can access admin dashboard', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk();
});

test('admin users can access chat and see their contacts', function () {
    $admin = User::factory()->admin()->create();
    $contact = User::factory()->create(['role' => UserRole::User]);
    $admin->contacts()->attach($contact->id);

    $this->actingAs($admin)
        ->get(route('chat'))
        ->assertOk()
        ->assertSee($contact->email);
});

test('admin users are redirected to admin dashboard when attempting to access user dashboard', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.dashboard'));
});

test('user role helper methods work correctly', function () {
    $user = User::factory()->create(['role' => UserRole::User]);
    $admin = User::factory()->admin()->create();

    expect($user->isUser())->toBeTrue()
        ->and($user->isAdmin())->toBeFalse()
        ->and($user->hasRole(UserRole::User))->toBeTrue()
        ->and($user->hasRole('user'))->toBeTrue();

    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->isUser())->toBeFalse()
        ->and($admin->hasRole(UserRole::Admin))->toBeTrue()
        ->and($admin->hasRole('admin'))->toBeTrue();
});
