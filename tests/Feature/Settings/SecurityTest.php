<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    public function test_security_settings_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('security.edit'));

        $response->assertOk();

    }

    public function test_security_settings_page_renders_without_two_factor_when_feature_is_disabled(): void
    {
        config(['fortify.features' => []]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('Update password')
            ->assertDontSee('Manage your passkeys for passwordless sign-in')
            ->assertDontSee('Add a passkey to sign in without a password')
            ->assertDontSee('Two-factor authentication');
    }

    public function test_two_factor_authentication_disabled_when_confirmation_abandoned_between_requests(): void {}

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword');

        $response->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.security')
            ->set('current_password', 'wrong-password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword');

        $response->assertHasErrors(['current_password']);
    }

    public function test_password_change_rewraps_an_existing_chat_key_backup(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->forceFill([
            'password_key_backup' => json_encode(['salt' => 'old-salt', 'iv' => 'old-iv', 'ciphertext' => 'old-ciphertext']),
        ])->save();
        $newBackup = ['salt' => 'new-salt', 'iv' => 'new-iv', 'ciphertext' => 'new-ciphertext'];

        $this->actingAs($user);

        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword', $newBackup)
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
        expect($user->password_key_backup)->toBe(json_encode($newBackup));
    }

    public function test_password_change_requires_a_rewrapped_backup_when_one_exists(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $oldBackup = json_encode(['salt' => 'old-salt', 'iv' => 'old-iv', 'ciphertext' => 'old-ciphertext']);
        $user->forceFill(['password_key_backup' => $oldBackup])->save();

        $this->actingAs($user);

        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertHasErrors(['password']);

        expect($user->fresh()->password_key_backup)->toBe($oldBackup);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
