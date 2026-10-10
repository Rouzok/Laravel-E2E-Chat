<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sender_id
 * @property int $receiver_id
 * @property int|null $sender_key_version
 * @property int|null $receiver_key_version
 * @property string $encrypted_payload
 * @property string $iv
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['sender_id', 'receiver_id', 'sender_key_version', 'receiver_key_version', 'encrypted_payload', 'iv'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
