<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password and rewrap their chat key.
     *
     * @param  array<string, mixed>  $input
     */
    public function reset(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'password' => $this->passwordRules(),
            'recovery_proof' => ['required', 'string', 'size:44'],
            'password_key_backup' => ['required', 'array'],
            'password_key_backup.salt' => ['required', 'string'],
            'password_key_backup.iv' => ['required', 'string'],
            'password_key_backup.ciphertext' => ['required', 'string'],
        ])->validate();

        if (! $user->recovery_key_verifier || ! hash_equals($user->recovery_key_verifier, $validated['recovery_proof'])) {
            throw ValidationException::withMessages([
                'recovery_phrase' => 'The recovery phrase is incorrect.',
            ]);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'password_key_backup' => json_encode($validated['password_key_backup'], JSON_THROW_ON_ERROR),
        ])->save();
    }
}
