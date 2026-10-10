<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property string|null $public_key
 * @property int $public_key_version
 * @property string|null $password_key_backup
 * @property string|null $recovery_key_backup
 * @property string|null $recovery_key_verifier
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'public_key'])]
#[Hidden(['password', 'password_key_backup', 'recovery_key_backup', 'recovery_key_verifier', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'public_key_version' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isUser(): bool
    {
        return $this->role === UserRole::User;
    }

    public function hasRole(UserRole|string $role): bool
    {
        if (is_string($role)) {
            $role = UserRole::tryFrom($role);
        }

        return $this->role === $role;
    }

    /** @return HasMany<Message, $this> */
    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /** @return HasMany<Message, $this> */
    public function receivedMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    /** @return HasMany<UserPublicKey, $this> */
    public function publicKeys(): HasMany
    {
        return $this->hasMany(UserPublicKey::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'contacts', 'user_id', 'contact_id')
            ->withPivot('custom_name')
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_blocks', 'blocker_id', 'blocked_id')
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function blockedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_blocks', 'blocked_id', 'blocker_id')
            ->withTimestamps();
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
