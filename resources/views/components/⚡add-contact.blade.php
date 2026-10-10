<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    public bool $isOpen = false;

    #[Validate('required|email|max:255')]
    public string $email = '';

    public ?string $successMessage = null;

    public function openModal(): void
    {
        $this->isOpen = true;
        $this->reset('email', 'successMessage');
        $this->resetValidation();
    }

    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->reset('email', 'successMessage');
        $this->resetValidation();
    }

    public function addContact(): void
    {
        $this->validate();

        $authUser = Auth::user();

        $contact = User::where('email', $this->email)
            ->where('role', 'user')
            ->first();

        if (! $contact) {
            $this->addError('email', __('No user found with that email address.'));

            return;
        }

        if ($contact->id === $authUser->id) {
            $this->addError('email', __('You cannot add yourself as a contact.'));

            return;
        }

        if ($authUser->contacts()->where('contact_id', $contact->id)->exists()) {
            $this->addError('email', __('This user is already in your contact list.'));

            return;
        }

        $authUser->contacts()->attach($contact->id);

        $this->successMessage = __(':name has been added to your contacts.', ['name' => $contact->name]);

        $this->dispatch('contact-added');
        $this->dispatch('contacts-updated', users: $authUser->contacts()
            ->where('role', 'user')
            ->select(['users.id', 'users.name', 'users.email', 'users.public_key'])
            ->get(), addedContactId: $contact->id);
        $this->isOpen = false;

        $this->reset('email');
    }
}; ?>

<div x-data="{ isOpen: false }" x-on:contact-added.window="isOpen = false">
    {{-- Add Contact Button --}}
    <button
        type="button"
        wire:click="openModal"
        x-on:click="isOpen = true"
        class="flex items-center gap-2 w-full px-4 py-2.5 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 transition-colors border-b border-neutral-200 dark:border-neutral-700"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        {{ __('Add Contact') }}
    </button>

    {{-- Modal Overlay --}}
    <div
            x-cloak
            x-show="isOpen"
            x-transition.opacity.duration.150ms
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm"
            x-on:click.self="isOpen = false; $wire.closeModal()"
        >
            <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">
                        {{ __('Add New Contact') }}
                    </h3>
                    <button
                        type="button"
                        wire:click="closeModal"
                        x-on:click="isOpen = false"
                        class="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition-colors"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                @if ($successMessage)
                    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 text-sm text-emerald-700 dark:text-emerald-300 flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {{ $successMessage }}
                    </div>
                @endif

                <form wire:submit="addContact">
                    <div class="mb-4">
                        <label for="contact-email" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1.5">
                            {{ __('Email Address') }}
                        </label>
                        <input
                            id="contact-email"
                            type="email"
                            wire:model="email"
                            placeholder="{{ __('Enter email address...') }}"
                            class="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-zinc-700 px-4 py-2 text-sm text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-400"
                            autocomplete="off"
                        />
                        @error('email')
                            <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center gap-3 justify-end">
                        <button
                            type="button"
                            wire:click="closeModal"
                            x-on:click="isOpen = false"
                            class="px-4 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-100 dark:hover:bg-zinc-700 rounded-lg transition-colors"
                        >
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            class="px-4 py-2 text-sm font-medium bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition-colors flex items-center gap-1.5"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-75"
                        >
                            <span wire:loading wire:target="addContact">
                                <svg class="animate-spin size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                            </span>
                            {{ __('Add Contact') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
</div>
