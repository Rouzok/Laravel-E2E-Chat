<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class PasswordRecoveryController extends Controller
{
    /**
     * Return an encrypted recovery backup only for a valid email reset token.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
        ]);
        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Password::broker()->tokenExists($user, $validated['token'])) {
            return response()->json(['message' => 'The password reset link is invalid or has expired.'], 422);
        }

        if (! $user->recovery_key_backup) {
            return response()->json(['message' => 'This account has no recovery key backup.'], 422);
        }

        return response()->json([
            'recovery_key_backup' => json_decode($user->recovery_key_backup, true, flags: JSON_THROW_ON_ERROR),
        ]);
    }
}
