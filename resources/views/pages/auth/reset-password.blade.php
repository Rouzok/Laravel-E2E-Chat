<x-layouts::auth :title="__('Reset password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Reset password')" :description="__('Enter your recovery phrase and new password below')" />
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form id="password-reset-form" method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6">
            @csrf
            <input type="hidden" name="token" value="{{ request()->route('token') }}">
            <flux:input name="email" value="{{ request('email') }}" :label="__('Email')" type="email" required autocomplete="email" />

            <flux:textarea id="recovery-phrase" :label="__('24-word recovery phrase')" required rows="3" autocomplete="off" />
            @error('recovery_phrase')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
            <p id="recovery-error" role="alert" class="text-sm text-red-600"></p>

            <flux:input name="password" :label="__('New password')" type="password" required autocomplete="new-password" :placeholder="__('Password')" passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}" viewable />
            <flux:input name="password_confirmation" :label="__('Confirm password')" type="password" required autocomplete="new-password" :placeholder="__('Password')" passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}" viewable />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="reset-password-button">{{ __('Reset password') }}</flux:button>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('#password-reset-form');
            const error = document.querySelector('#recovery-error');
            let submitting = false;

            form.addEventListener('submit', async (event) => {
                if (submitting) return;
                event.preventDefault();
                error.textContent = '';

                try {
                    const token = form.elements.namedItem('token').value;
                    const email = form.elements.namedItem('email').value;
                    const phrase = form.querySelector('#recovery-phrase').value;
                    const password = form.elements.namedItem('password').value;
                    const backupResponse = await fetch(@js(route('password.key-backup')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ token, email }),
                    });
                    if (!backupResponse.ok) throw new Error('The reset link is invalid or expired, or this account has no recovery backup.');

                    const { recovery_key_backup: recoveryBackup } = await backupResponse.json();
                    const restored = await window.E2EE.restoreKeyBackup(recoveryBackup, phrase, true);
                    const passwordBackup = await window.E2EE.encryptKeyBackup(restored, password);
                    addHidden('recovery_proof', restored.verifier);
                    addHidden('password_key_backup[salt]', passwordBackup.salt);
                    addHidden('password_key_backup[iv]', passwordBackup.iv);
                    addHidden('password_key_backup[ciphertext]', passwordBackup.ciphertext);
                    form.querySelector('#recovery-phrase').disabled = true;
                    submitting = true;
                    form.requestSubmit();
                } catch (exception) {
                    error.textContent = exception.message.includes('reset link')
                        ? exception.message
                        : 'The recovery phrase is incorrect. Check it and try again.';
                }
            });

            function addHidden(name, value) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.append(input);
            }
        });
    </script>
</x-layouts::auth>
