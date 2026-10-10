<?php

use App\Events\CleanupUpdated;
use App\Events\BlockStatusUpdated;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Contact Profile')] class extends Component {
    #[Locked]
    public int $contactId;

    public string $customName = '';

    public string $reportReason = '';

    public string $reportDetails = '';

    public bool $reportSubmitted = false;

    public function mount(User $user): void
    {
        $this->contactId = $user->id;
        $this->contact();
        $this->customName = $this->contact()->pivot->custom_name ?? '';
    }

    public function getContactProperty(): User
    {
        return $this->contact();
    }

    public function getCleanupRequestProperty(): ?object
    {
        $userId = Auth::id();

        return DB::table('cleanup_requests')
            ->where('status', 'pending')
            ->where(function ($query) use ($userId) {
                $query->where(function ($query) use ($userId) {
                    $query->where('requester_id', $userId)->where('other_user_id', $this->contactId);
                })->orWhere(function ($query) use ($userId) {
                    $query->where('requester_id', $this->contactId)->where('other_user_id', $userId);
                });
            })
            ->latest('id')
            ->first();
    }

    public function getBlockedByMeProperty(): bool
    {
        return Auth::user()->blockedUsers()->whereKey($this->contactId)->exists();
    }

    public function getBlockedMeProperty(): bool
    {
        return Auth::user()->blockedByUsers()->whereKey($this->contactId)->exists();
    }

    public function saveCustomName(): void
    {
        $this->contact();

        $validated = $this->validate([
            'customName' => ['nullable', 'string', 'max:80'],
        ]);

        $customName = trim((string) $validated['customName']);
        Auth::user()->contacts()->updateExistingPivot($this->contactId, [
            'custom_name' => $customName === '' ? null : $customName,
        ]);

        $this->customName = $customName;
        $this->dispatch('contact-name-updated');
    }

    public function requestCleanup(): void
    {
        $this->contact();

        $request = DB::transaction(function (): object {
            $existingRequest = DB::table('cleanup_requests')
                ->where('status', 'pending')
                ->where(function ($query) {
                    $query->where(function ($query) {
                        $query->where('requester_id', Auth::id())->where('other_user_id', $this->contactId);
                    })->orWhere(function ($query) {
                        $query->where('requester_id', $this->contactId)->where('other_user_id', Auth::id());
                    });
                })
                ->lockForUpdate()
                ->first();

            if ($existingRequest) {
                return $existingRequest;
            }

            $lastMessageId = Message::query()
                ->where(function ($query) {
                    $query->where('sender_id', Auth::id())->where('receiver_id', $this->contactId);
                })
                ->orWhere(function ($query) {
                    $query->where('sender_id', $this->contactId)->where('receiver_id', Auth::id());
                })
                ->max('id');

            $requestId = DB::table('cleanup_requests')->insertGetId([
                'requester_id' => Auth::id(),
                'other_user_id' => $this->contactId,
                'message_cutoff_id' => $lastMessageId,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('cleanup_requests')->find($requestId);
        });

        CleanupUpdated::dispatch($request->requester_id, $request->other_user_id, $request->status);
    }

    public function approveCleanup(int $requestId): void
    {
        $this->contact();

        $request = DB::transaction(function () use ($requestId): object {
            $request = DB::table('cleanup_requests')->where('id', $requestId)->lockForUpdate()->first();

            abort_unless($request && $request->status === 'pending', 404);
            abort_unless((int) $request->requester_id === $this->contactId
                && (int) $request->other_user_id === (int) Auth::id(), 403);

            if ($request->message_cutoff_id !== null) {
                Message::query()
                    ->where(function ($query) use ($request) {
                        $query->where(function ($query) use ($request) {
                            $query->where('sender_id', $request->requester_id)->where('receiver_id', $request->other_user_id);
                        })->orWhere(function ($query) use ($request) {
                            $query->where('sender_id', $request->other_user_id)->where('receiver_id', $request->requester_id);
                        });
                    })
                    ->where('id', '<=', $request->message_cutoff_id)
                    ->delete();
            }

            DB::table('cleanup_requests')->where('id', $requestId)->update([
                'status' => 'approved',
                'updated_at' => now(),
            ]);

            return (object) [...(array) $request, 'status' => 'approved'];
        });

        CleanupUpdated::dispatch($request->requester_id, $request->other_user_id, 'approved');
    }

    public function declineCleanup(int $requestId): void
    {
        $this->contact();

        $request = $this->changeCleanupStatus($requestId, 'declined', 'approve');
        CleanupUpdated::dispatch($request->requester_id, $request->other_user_id, 'declined');
    }

    public function cancelCleanup(int $requestId): void
    {
        $this->contact();

        $request = $this->changeCleanupStatus($requestId, 'cancelled', 'cancel');
        CleanupUpdated::dispatch($request->requester_id, $request->other_user_id, 'cancelled');
    }

    public function blockUser(): void
    {
        $this->contact();

        Auth::user()->blockedUsers()->syncWithoutDetaching([$this->contactId]);
        BlockStatusUpdated::dispatch(Auth::id(), $this->contactId, true);
    }

    public function unblockUser(): void
    {
        $this->contact();

        Auth::user()->blockedUsers()->detach($this->contactId);
        BlockStatusUpdated::dispatch(Auth::id(), $this->contactId, false);
    }

    public function reportUser(): void
    {
        $this->contact();

        $validated = $this->validate([
            'reportReason' => ['required', 'string', 'max:120'],
            'reportDetails' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::table('user_reports')->insert([
            'reporter_id' => Auth::id(),
            'reported_user_id' => $this->contactId,
            'reason' => trim($validated['reportReason']),
            'details' => trim((string) $validated['reportDetails']) ?: null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->reset('reportReason', 'reportDetails');
        $this->reportSubmitted = true;
    }

    private function contact(): User
    {
        $contact = Auth::user()->contacts()->whereKey($this->contactId)->first();

        abort_unless($contact, 404);

        return $contact;
    }

    private function changeCleanupStatus(int $requestId, string $status, string $action): object
    {
        return DB::transaction(function () use ($requestId, $status, $action): object {
            $request = DB::table('cleanup_requests')->where('id', $requestId)->lockForUpdate()->first();

            abort_unless($request && $request->status === 'pending', 404);

            $isRequester = (int) $request->requester_id === (int) Auth::id();
            $isOtherParticipant = (int) $request->other_user_id === (int) Auth::id();
            abort_unless($isRequester || $isOtherParticipant, 403);

            if ($action === 'approve') {
                abort_unless($isOtherParticipant && (int) $request->requester_id === $this->contactId, 403);
            }

            if ($action === 'cancel') {
                abort_unless($isRequester && (int) $request->other_user_id === $this->contactId, 403);
            }

            DB::table('cleanup_requests')->where('id', $requestId)->update([
                'status' => $status,
                'updated_at' => now(),
            ]);

            return (object) [...(array) $request, 'status' => $status];
        });
    }
}; ?>

<div wire:poll.5s class="mx-auto w-full max-w-3xl space-y-6 p-6">
    <div class="flex items-center gap-4">
        <a href="{{ route('chat') }}" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-100 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
            {{ __('Back to conversation') }}
        </a>
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ trim($customName) !== '' ? $customName : $this->contact->email }}</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Account name: :name · :email', ['name' => $this->contact->name, 'email' => $this->contact->email]) }}</p>
        </div>
    </div>

    <section class="space-y-4 rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
        <div>
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Your contact name') }}</h2>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">{{ __('Only you see this name. Leave it blank to show the email address: :email', ['email' => $this->contact->email]) }}</p>
        </div>
        <form wire:submit="saveCustomName" class="flex flex-col gap-3 sm:flex-row">
            <input wire:model="customName" maxlength="80" type="text" placeholder="{{ $this->contact->email }}" class="min-w-0 flex-1 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 dark:border-neutral-700 dark:bg-zinc-800 dark:text-neutral-100" />
            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">{{ __('Save name') }}</button>
        </form>
        @error('customName') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </section>

    <section class="space-y-4 rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
        <div>
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Vote to clean chat') }}</h2>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">{{ __('Messages are deleted for both people only after both agree. This request covers messages already in the conversation; newer messages are kept.') }}</p>
        </div>
        @if ($this->cleanupRequest)
            <div class="space-y-3 rounded-lg bg-neutral-50 p-4 text-sm dark:bg-zinc-800">
                <p>{{ $this->cleanupRequest->requester_id === auth()->id() ? __('You requested a chat cleanup.') : __(':name requested a chat cleanup.', ['name' => $this->contact->name]) }}</p>
                @if ($this->cleanupRequest->requester_id === auth()->id())
                    <button type="button" wire:click="cancelCleanup({{ $this->cleanupRequest->id }})" class="rounded-lg border border-neutral-300 px-3 py-2 dark:border-neutral-600">{{ __('Cancel request') }}</button>
                @else
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="approveCleanup({{ $this->cleanupRequest->id }})" onclick="return confirm('{{ __('Agreeing permanently deletes the covered encrypted messages for both people. Continue?') }}')" class="rounded-lg bg-red-600 px-3 py-2 font-medium text-white">{{ __('Approve and delete') }}</button>
                        <button type="button" wire:click="declineCleanup({{ $this->cleanupRequest->id }})" class="rounded-lg border border-neutral-300 px-3 py-2 dark:border-neutral-600">{{ __('Decline') }}</button>
                    </div>
                @endif
            </div>
        @else
            <button type="button" wire:click="requestCleanup" onclick="return confirm('{{ __('This opens a request to delete the current conversation history for both people after the other person agrees. Continue?') }}')" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/30">{{ __('Vote to clean chat') }}</button>
        @endif
    </section>

    <section class="space-y-3 rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
        <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Block user') }}</h2>
        <p class="text-sm text-neutral-600 dark:text-neutral-400">{{ $this->blockedMe ? __('This person has blocked you. Messages are disabled while either person has a block in place.') : __('Blocking keeps the existing conversation and prevents new messages in both directions.') }}</p>
        @if ($this->blockedByMe)
            <button type="button" wire:click="unblockUser" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-600">{{ __('Unblock user') }}</button>
        @else
            <button type="button" wire:click="blockUser" onclick="return confirm('{{ __('Block this person from sending messages to you?') }}')" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 dark:border-red-900 dark:text-red-300">{{ __('Block user') }}</button>
        @endif
    </section>

    <section class="space-y-4 rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
        <div>
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Report user') }}</h2>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">{{ __('Reports are private and sent to an administrator for review. They do not block this person or delete messages.') }}</p>
        </div>
        @if ($reportSubmitted)
            <p role="status" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">{{ __('Your report has been submitted for review.') }}</p>
        @endif
        <form wire:submit="reportUser" class="space-y-3">
            <div>
                <label for="report-reason" class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Reason') }}</label>
                <input id="report-reason" wire:model="reportReason" maxlength="120" required class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-zinc-800" />
                @error('reportReason') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="report-details" class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Additional details (optional)') }}</label>
                <textarea id="report-details" wire:model="reportDetails" maxlength="2000" rows="4" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-zinc-800"></textarea>
                @error('reportDetails') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">{{ __('Submit report') }}</button>
        </form>
    </section>
</div>
