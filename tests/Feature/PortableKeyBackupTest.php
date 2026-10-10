<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

it('stores encrypted backups only for the authenticated user', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $passwordBackup = [
        'salt' => 'password-salt',
        'iv' => 'password-iv',
        'ciphertext' => 'password-encrypted-private-key',
    ];
    $recoveryBackup = [
        'salt' => 'recovery-salt',
        'iv' => 'recovery-iv',
        'ciphertext' => 'recovery-encrypted-private-key',
    ];

    $response = $this->actingAs($owner)->putJson(route('user.key-backup.store'), [
        'public_key' => '{"kty":"EC","crv":"P-256","x":"owner-x","y":"owner-y"}',
        'public_key_version' => 1,
        'password_key_backup' => $passwordBackup,
        'recovery_key_backup' => $recoveryBackup,
        'recovery_key_verifier' => str_repeat('a', 44),
    ]);

    $response->assertOk()->assertJson(['version' => 1]);
    $this->assertDatabaseHas('users', [
        'id' => $owner->id,
        'public_key_version' => 1,
        'password_key_backup' => json_encode($passwordBackup),
        'recovery_key_backup' => json_encode($recoveryBackup),
        'recovery_key_verifier' => str_repeat('a', 44),
    ]);
    $this->assertDatabaseHas('user_public_keys', [
        'user_id' => $owner->id,
        'version' => 1,
        'public_key' => '{"kty":"EC","crv":"P-256","x":"owner-x","y":"owner-y"}',
    ]);
    expect($owner->fresh()->toArray())->not->toHaveKey('password_key_backup');

    $this->actingAs($otherUser)
        ->getJson(route('user.key-backup.show'))
        ->assertOk()
        ->assertJson(['password_key_backup' => null, 'has_backup' => false]);
});

it('rejects an attempt to replace an existing public key version', function () {
    $user = User::factory()->create();
    $user->forceFill(['public_key' => '{"x":"existing"}', 'public_key_version' => 1])->save();
    $user->publicKeys()->create(['version' => 1, 'public_key' => '{"x":"existing"}']);

    $this->actingAs($user)->putJson(route('user.public-key.store'), [
        'public_key' => '{"x":"replacement"}',
        'version' => 1,
    ])->assertUnprocessable();

    expect($user->fresh()->public_key)->toBe('{"x":"existing"}');
});

it('requires a valid email reset token before returning the encrypted recovery backup', function () {
    $user = User::factory()->create();
    $recoveryBackup = ['salt' => 'phrase-salt', 'iv' => 'phrase-iv', 'ciphertext' => 'encrypted-key'];
    $user->forceFill([
        'recovery_key_backup' => json_encode($recoveryBackup),
        'recovery_key_verifier' => str_repeat('a', 44),
    ])->save();
    $token = Password::broker()->createToken($user);

    $this->postJson(route('password.key-backup'), [
        'email' => $user->email,
        'token' => 'invalid-token',
    ])->assertUnprocessable();

    $this->postJson(route('password.key-backup'), [
        'email' => $user->email,
        'token' => $token,
    ])->assertOk()
        ->assertJson(['recovery_key_backup' => $recoveryBackup])
        ->assertJsonMissingPath('recovery_key_verifier');
});

it('requires authentication to access the password key backup', function () {
    $this->getJson(route('user.key-backup.show'))->assertUnauthorized();
});
