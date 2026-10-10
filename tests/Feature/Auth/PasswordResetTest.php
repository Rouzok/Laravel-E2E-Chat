<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token_and_recovery_phrase_proof(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $proof = str_repeat('a', 44);
        $passwordBackup = ['salt' => 'new-salt', 'iv' => 'new-iv', 'ciphertext' => 'new-ciphertext'];
        $user->forceFill([
            'recovery_key_backup' => json_encode(['salt' => 'phrase-salt', 'iv' => 'phrase-iv', 'ciphertext' => 'encrypted-key']),
            'recovery_key_verifier' => $proof,
        ])->save();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user, $proof, $passwordBackup) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
                'recovery_proof' => $proof,
                'password_key_backup' => $passwordBackup,
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login', absolute: false));

            expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue()
                ->and($user->fresh()->password_key_backup)->toBe(json_encode($passwordBackup));

            return true;
        });
    }

    public function test_password_reset_rejects_an_incorrect_recovery_phrase_proof(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $originalPassword = $user->password;
        $originalBackup = json_encode(['salt' => 'phrase-salt', 'iv' => 'phrase-iv', 'ciphertext' => 'encrypted-key']);
        $user->forceFill([
            'password_key_backup' => json_encode(['salt' => 'old-password-salt', 'iv' => 'old-password-iv', 'ciphertext' => 'old-password-backup']),
            'recovery_key_backup' => $originalBackup,
            'recovery_key_verifier' => str_repeat('a', 44),
        ])->save();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user, $originalPassword, $originalBackup) {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
                'recovery_proof' => str_repeat('b', 44),
                'password_key_backup' => ['salt' => 'new-salt', 'iv' => 'new-iv', 'ciphertext' => 'new-ciphertext'],
            ])->assertSessionHasErrors('recovery_phrase');

            expect($user->fresh()->password)->toBe($originalPassword)
                ->and($user->fresh()->recovery_key_backup)->toBe($originalBackup)
                ->and($user->fresh()->password_key_backup)->toBe(json_encode(['salt' => 'old-password-salt', 'iv' => 'old-password-iv', 'ciphertext' => 'old-password-backup']));

            return true;
        });
    }
}
