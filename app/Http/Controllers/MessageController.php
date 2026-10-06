<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]);

        /** @var User $sender */
        $sender = $request->user();

        $message = Message::create([
            'sender_id' => $sender->id,
            'receiver_id' => $validated['receiver_id'],
            'encrypted_payload' => $validated['encrypted_payload'],
            'iv' => $validated['iv'],
        ]);

        MessageSent::dispatch($message);

        return response()->json($message, 201);
    }

    /**
     * Retrieve the encrypted message history between the authenticated user and another user.
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
     * Store or update the authenticated user's ECDH public key.
     */
    public function storePublicKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'public_key' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->update(['public_key' => $validated['public_key']]);

        return response()->json(['message' => 'Public key updated.']);
    }

    /**
     * Get the public key of a specific user (needed for ECDH key exchange).
     */
    public function publicKey(User $user): JsonResponse
    {
        return response()->json(['public_key' => $user->public_key]);
    }
}
