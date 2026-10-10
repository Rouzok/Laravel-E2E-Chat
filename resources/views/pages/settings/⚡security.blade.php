<?php

use App\Concerns\PasswordValidationRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';



    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {

    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(?array $passwordKeyBackup = null): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        $user = Auth::user();

        if ($user->password_key_backup !== null) {
            if ($passwordKeyBackup === null) {
                throw ValidationException::withMessages([
                    'password' => 'Rewrapping the encrypted chat key backup is required.',
                ]);
            }

            $passwordKeyBackup = Validator::make(
                ['password_key_backup' => $passwordKeyBackup],
                [
                    'password_key_backup' => ['required', 'array'],
                    'password_key_backup.salt' => ['required', 'string'],
                    'password_key_backup.iv' => ['required', 'string'],
                    'password_key_backup.ciphertext' => ['required', 'string'],
                ],
            )->validate()['password_key_backup'];

            $user->forceFill([
                'password' => $validated['password'],
                'password_key_backup' => json_encode($passwordKeyBackup, JSON_THROW_ON_ERROR),
            ])->save();
        } else {
            $user->update(['password' => $validated['password']]);
        }

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }


}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Security settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        <form method="POST" x-on:submit.prevent="window.updatePasswordBackup($wire, $el)" class="mt-6 space-y-6">
            <flux:input
                wire:model="current_password"
                :label="__('Current password')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />
            <flux:input
                wire:model="password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <p data-password-backup-error role="alert" class="text-sm text-red-600"></p>
            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="update-password-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>


    </x-pages::settings.layout>

</section>
