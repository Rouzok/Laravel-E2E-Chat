<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Message;
use App\Models\User;
use App\Models\UserPublicKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    /**
     * Store a new encrypted message and broadcast it to the recipient.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receiver_id' => ['required', 'integer', 'exists:users,id'],
            'encrypted_payload' => ['required', 'string'],
            'iv' => ['required', 'string'],
            'sender_key_version' => ['sometimes', 'integer', 'min:1'],
            'receiver_key_version' => ['sometimes', 'integer', 'min:1'],
        ]);

        /** @var User $sender */
        $sender = $request->user();
        $receiver = User::findOrFail($validated['receiver_id']);
        $senderKeyVersion = (int) ($validated['sender_key_version'] ?? $sender->public_key_version);
        $receiverKeyVersion = (int) ($validated['receiver_key_version'] ?? $receiver->public_key_version);

        if (($senderKeyVersion > 0 && ! $sender->publicKeys()->where('version', $senderKeyVersion)->exists())
            || ($receiverKeyVersion > 0 && ! $receiver->publicKeys()->where('version', $receiverKeyVersion)->exists())) {
            return response()->json(['message' => 'A message key version is unavailable.'], 422);
        }

        if ($receiver->is($sender)) {
            return response()->json(['message' => __('You cannot message yourself.')], 422);
        }

        if ($sender->blockedUsers()->whereKey($receiver->id)->exists()
            || $sender->blockedByUsers()->whereKey($receiver->id)->exists()) {
            return response()->json(['message' => __('Messaging is unavailable while either person has blocked the other.')], 403);
        }

        $message = DB::transaction(function () use ($sender, $receiver, $validated, $senderKeyVersion, $receiverKeyVersion): Message {
            $message = Message::create([
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'encrypted_payload' => $validated['encrypted_payload'],
                'iv' => $validated['iv'],
                'sender_key_version' => $senderKeyVersion ?: null,
                'receiver_key_version' => $receiverKeyVersion ?: null,
            ]);

            DB::table('contacts')->insertOrIgnore([
                'user_id' => $receiver->id,
                'contact_id' => $sender->id,
                'custom_name' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $message;
        });

        MessageSent::dispatch($message);

        return response()->json($message, 201);
    }

    /**
     * Retrieve encrypted history between the authenticated user and another user.
     */
    public function history(Request $request, User $user): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $messages = Message::where(function ($query) use ($authUser, $user) {
            $query->where('sender_id', $authUser->id)
                ->where('receiver_id', $user->id);
        })->orWhere(function ($query) use ($authUser, $user) {
            $query->where('sender_id', $user->id)
                ->where('receiver_id', $authUser->id);
        })->orderBy('created_at')->get();

        return response()->json($messages);
    }

    /**
     * Store a versioned ECDH public key for the authenticated user.
     */
    public function storePublicKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'public_key' => ['required', 'string'],
            'version' => ['sometimes', 'integer', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $version = $validated['version'] ?? max(1, $user->public_key_version);
        $this->savePublicKeyVersion($user, $validated['public_key'], $version);

        return response()->json(['message' => 'Public key updated.', 'version' => $version]);
    }

    /**
     * Return a public key version used to derive a historical message key.
     */
    public function publicKey(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['sometimes', 'integer', 'min:1'],
        ]);

        $version = $validated['version'] ?? $user->public_key_version;
        $key = $version > 0
            ? UserPublicKey::whereBelongsTo($user)->where('version', $version)->value('public_key')
            : null;

        if ($key === null && $version === $user->public_key_version) {
            $key = $user->public_key;
        }

        return response()->json(['public_key' => $key, 'version' => $version]);
    }

    /**
     * Return the authenticated user's encrypted password backup for browser restore.
     */
    public function keyBackup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'public_key' => $user->public_key,
            'public_key_version' => $user->public_key_version,
            'password_key_backup' => $user->password_key_backup
                ? json_decode($user->password_key_backup, true, flags: JSON_THROW_ON_ERROR)
                : null,
            'has_backup' => $user->password_key_backup !== null,
        ]);
    }

    /**
     * Store browser-encrypted key backups and publish their matching public key.
     */
    public function storeKeyBackup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'public_key' => ['required', 'string'],
            'public_key_version' => ['required', 'integer', 'min:1'],
            'password_key_backup' => ['required', 'array'],
            'password_key_backup.salt' => ['required', 'string'],
            'password_key_backup.iv' => ['required', 'string'],
            'password_key_backup.ciphertext' => ['required', 'string'],
            'recovery_key_backup' => ['required', 'array'],
            'recovery_key_backup.salt' => ['required', 'string'],
            'recovery_key_backup.iv' => ['required', 'string'],
            'recovery_key_backup.ciphertext' => ['required', 'string'],
            'recovery_key_verifier' => ['required', 'string', 'size:44'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $version = (int) $validated['public_key_version'];

        if ($version < $user->public_key_version) {
            return response()->json(['message' => 'A previous key version cannot replace the current key.'], 422);
        }

        DB::transaction(function () use ($user, $validated, $version): void {
            $this->savePublicKeyVersion($user, $validated['public_key'], $version);
            $user->forceFill([
                'password_key_backup' => json_encode($validated['password_key_backup'], JSON_THROW_ON_ERROR),
                'recovery_key_backup' => json_encode($validated['recovery_key_backup'], JSON_THROW_ON_ERROR),
                'recovery_key_verifier' => $validated['recovery_key_verifier'],
            ])->save();
        });

        return response()->json(['message' => 'Encrypted key backups saved.', 'version' => $version]);
    }

    private function savePublicKeyVersion(User $user, string $publicKey, int $version): void
    {
        $existing = UserPublicKey::whereBelongsTo($user)->where('version', $version)->first();

        if ($existing && $existing->public_key !== $publicKey) {
            abort(422, 'A public key version cannot be changed.');
        }

        UserPublicKey::firstOrCreate(
            ['user_id' => $user->id, 'version' => $version],
            ['public_key' => $publicKey],
        );

        if ($version >= $user->public_key_version) {
            $user->forceFill([
                'public_key' => $publicKey,
                'public_key_version' => $version,
            ])->save();
        }
    }
}
