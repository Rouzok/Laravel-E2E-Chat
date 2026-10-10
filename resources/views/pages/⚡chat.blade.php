<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Chat')] class extends Component {
    /**
     * Get the authenticated user's contacts available to chat with.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function getUsersProperty()
    {
        $authUser = Auth::user();
        $blockedUserIds = $authUser->blockedUsers()->pluck('users.id')->all();
        $blockedByUserIds = $authUser->blockedByUsers()->pluck('users.id')->all();

        return $authUser
            ->contacts()
            ->where('role', 'user')
            ->select(['users.id', 'users.name', 'users.email', 'users.public_key', 'users.public_key_version'])
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'public_key' => $user->public_key,
                'public_key_version' => $user->public_key_version,
                'display_name' => $user->pivot->custom_name ?: $user->email,
                'blocked_by_me' => in_array($user->id, $blockedUserIds),
                'blocked_me' => in_array($user->id, $blockedByUserIds),
            ]);
    }

    /**
     * Refresh the contact list when a new contact is added.
     */
    #[On('contact-added')]
    public function refreshContacts(): void
    {
        // Re-renders the component so the updated $this->users is passed to Alpine
    }
}; ?>


<div x-data="chatComponent({
    authId: {{ auth()->id() }},
    usersList: {{ Js::from($this->users) }}
})" x-init="initChat()" x-on:contacts-updated.window="updateContacts($event.detail)"
    class="flex h-full min-h-0 w-full flex-1 overflow-hidden border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-zinc-900 shadow-sm">
    <div x-show="keySetupMode" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <section class="w-full max-w-xl space-y-4 rounded-xl bg-white p-6 shadow-xl dark:bg-zinc-900" role="dialog" aria-modal="true">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100" x-text="keySetupMode === 'create' ? 'Save your recovery phrase' : 'Restore your chat key'"></h2>
            <p x-show="keySetupMode === 'create'" class="text-sm text-neutral-600 dark:text-neutral-300">Save this 24-word phrase somewhere private. It is shown only once and is required to recover your chat key after a password reset.</p>
            <textarea x-show="keySetupMode === 'create'" x-model="recoveryPhrase" readonly rows="4" class="w-full rounded-lg border border-neutral-300 bg-neutral-50 p-3 font-mono text-sm dark:border-neutral-700 dark:bg-zinc-800"></textarea>
            <label x-show="keySetupMode === 'create'" class="flex items-start gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                <input type="checkbox" x-model="recoveryPhraseSaved" class="mt-1 rounded">
                <span>I saved the recovery phrase somewhere private.</span>
            </label>
            <label class="block space-y-1 text-sm text-neutral-700 dark:text-neutral-300">
                <span x-text="keySetupMode === 'create' ? 'Current account password' : 'Account password'"></span>
                <input type="password" x-model="keyPassword" autocomplete="current-password" class="w-full rounded-lg border border-neutral-300 px-3 py-2 dark:border-neutral-700 dark:bg-zinc-800">
            </label>
            <p x-show="keySetupError" x-text="keySetupError" role="alert" class="text-sm text-red-600"></p>
            <button type="button" @click="keySetupMode === 'create' ? savePortableKey() : restorePortableKey()" :disabled="keySetupBusy || !keyPassword || (keySetupMode === 'create' && !recoveryPhraseSaved)" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                <span x-text="keySetupBusy ? 'Working...' : (keySetupMode === 'create' ? 'Save phrase and continue' : 'Restore chat key')"></span>
            </button>
        </section>
    </div>

    <!-- Left Sidebar: Contact List -->
    <div
        class="w-1/3 min-w-[250px] max-w-[350px] flex flex-col border-r border-neutral-200 dark:border-neutral-700 bg-zinc-50 dark:bg-zinc-900/50">
        <div class="p-4 border-b border-neutral-200 dark:border-neutral-700">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Messages') }}
            </h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 flex items-center gap-1 mt-1" x-show="keyGenerated">
                <span class="inline-block size-2 rounded-full bg-emerald-500"></span>
                <span>{{ __('E2EE Enabled (ECDH / AES-256-GCM)') }}</span>
            </p>
        </div>

        <livewire:add-contact />

        <div class="flex-1 overflow-y-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <template x-for="user in users" :key="user.id">
                <button type="button" @click="selectUser(user)"
                    :class="selectedUser?.id === user.id ? 'bg-neutral-200/70 dark:bg-neutral-800' :
                        'hover:bg-neutral-100 dark:hover:bg-neutral-800/50'"
                    class="w-full p-4 flex items-center gap-3 text-left transition-colors cursor-pointer">
                    <div class="relative">
                        <div
                            class="size-10 rounded-full bg-indigo-500 text-white flex items-center justify-center font-semibold text-sm">
                            <span x-text="user.display_name.substring(0, 2).toUpperCase()"></span>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-neutral-900 dark:text-neutral-100 truncate"
                                x-text="user.display_name"></p>
                            <span x-show="unreadCounts[user.id]" x-text="unreadCounts[user.id]"
                                class="px-2 py-0.5 text-xs font-bold bg-indigo-600 text-white rounded-full"></span>
                        </div>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 truncate" x-text="user.email"></p>
                    </div>
                </button>
            </template>

            <div x-show="users.length === 0" class="p-4 text-center text-sm text-neutral-500 dark:text-neutral-400">
                {{ __('No contacts yet. Click "+ Add Contact" to get started.') }}
            </div>
        </div>
    </div>

    <!-- Right Chat Window -->
    <div class="flex-1 flex flex-col bg-white dark:bg-zinc-900">
        <template x-if="selectedUser">
            <div class="flex-1 flex flex-col h-full overflow-hidden">
                <!-- Chat Header -->
                <div
                    class="p-4 border-b border-neutral-200 dark:border-neutral-700 flex items-center justify-between bg-zinc-50/50 dark:bg-zinc-900/50">
                    <div class="flex items-center gap-3">
                        <a :href="'{{ route('contacts.show', ['user' => 'CONTACT_ID']) }}'.replace('CONTACT_ID', selectedUser.id)"
                            aria-label="{{ __('Open contact profile') }}"
                            class="size-9 rounded-full bg-indigo-500 text-white flex items-center justify-center font-semibold text-sm">
                            <span x-text="selectedUser.display_name.substring(0, 2).toUpperCase()"></span>
                        </a>
                        <div>
                            <a :href="'{{ route('contacts.show', ['user' => 'CONTACT_ID']) }}'.replace('CONTACT_ID', selectedUser.id)"
                                class="text-sm font-semibold text-neutral-900 hover:text-indigo-600 dark:text-neutral-100 dark:hover:text-indigo-400"
                                x-text="selectedUser.display_name"></a>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400" x-text="selectedUser.email"></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span x-show="isWaitingForContactKey"
                            class="text-xs text-amber-500 animate-pulse">{{ __('Waiting for contact encryption key...') }}</span>
                        <span x-show="isDerivingKey && !isWaitingForContactKey"
                            class="text-xs text-amber-500 animate-pulse">{{ __('Deriving Shared Secret...') }}</span>
                        <span x-show="sharedKeyReady"
                            class="text-xs px-2 py-1 rounded bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-medium">
                            🔒 {{ __('Encrypted') }}
                        </span>
                    </div>
                </div>

                <p x-show="selectedUser.blocked_by_me || selectedUser.blocked_me" role="status"
                    class="px-4 py-2 text-xs text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/30">
                    {{ __('Messaging is unavailable while either person has blocked the other.') }}
                </p>

                <p x-show="chatError" x-text="chatError" role="alert"
                    class="px-4 py-2 text-xs text-red-600 dark:text-red-400"></p>

                <!-- Messages Stream -->
                <div x-ref="messageBox" class="flex-1 overflow-y-auto p-4 space-y-3">
                    <template x-for="msg in messages" :key="msg.id || msg.temp_id">
                        <div :class="msg.sender_id === authId ? 'justify-end' : 'justify-start'" class="flex w-full">
                            <div :class="msg.sender_id === authId ?
                                'bg-indigo-600 text-white rounded-l-lg rounded-tr-lg' :
                                'bg-neutral-100 dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 rounded-r-lg rounded-tl-lg border border-neutral-200 dark:border-neutral-700'"
                                class="max-w-[70%] px-4 py-2 text-sm shadow-sm space-y-1">
                                <p class="break-words whitespace-pre-wrap" x-text="msg.text"></p>
                                <div :class="msg.sender_id === authId ? 'text-indigo-200' : 'text-neutral-400'"
                                    class="text-[10px] text-right">
                                    <span x-text="formatTime(msg.created_at)"></span>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div x-show="loadingHistory" class="text-center py-4 text-xs text-neutral-400">
                        {{ __('Loading and decrypting message history...') }}
                    </div>

                    <div x-show="!loadingHistory && messages.length === 0"
                        class="text-center py-8 text-sm text-neutral-400">
                        {{ __('No messages yet. Say hello!') }}
                    </div>
                </div>

                <!-- Input Footer -->
                <form @submit.prevent="sendMessage()"
                    class="p-3 border-t border-neutral-200 dark:border-neutral-700 flex items-center gap-2 bg-zinc-50/50 dark:bg-zinc-900/50">
                    <input type="text" x-model="newMessage" :disabled="!sharedKeyReady || sending || selectedUser.blocked_by_me || selectedUser.blocked_me"
                        placeholder="{{ __('Type an encrypted message...') }}"
                        class="flex-1 rounded-lg border border-neutral-300 dark:border-neutral-700 bg-white dark:bg-zinc-800 px-4 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50" />
                    <button type="submit" :disabled="!sharedKeyReady || !newMessage.trim() || sending || selectedUser.blocked_by_me || selectedUser.blocked_me"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50 flex items-center gap-1 cursor-pointer">
                        <span x-text="sending ? 'Sending...' : 'Send'"></span>
                    </button>
                </form>
                <p x-show="sendError" x-text="sendError" role="alert"
                    class="px-3 pb-2 text-xs text-red-600 dark:text-red-400"></p>
            </div>
        </template>

        <template x-if="!selectedUser">
            <div class="flex-1 flex flex-col items-center justify-center p-8 text-center text-neutral-400">
                <svg class="size-16 mb-4 stroke-1 text-neutral-300 dark:text-neutral-700" fill="none"
                    viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a.75.75 0 01-1.054-.916 4.38 4.38 0 00.32-1.34A8.253 8.253 0 013 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                </svg>
                <h3 class="text-base font-medium text-neutral-700 dark:text-neutral-300">
                    {{ __('Select a contact to start chatting') }}</h3>
                <p class="text-xs mt-1 max-w-sm">
                    {{ __('Messages are end-to-end encrypted. Plaintext messages and private keys never leave your browser.') }}
                </p>
            </div>
        </template>
    </div>
</div>

<script>
    Alpine.data('chatComponent', ({
        authId,
        usersList
    }) => ({
        authId: authId,
        users: usersList,
        selectedUser: null,
        keyPair: null,
        myPublicKeyJwk: null,
        identityVersion: 0,
        identityInitialized: false,
        keyBackupInfo: null,
        pendingKeyPair: null,
        recoveryPhrase: '',
        recoveryPhraseSaved: false,
        keyPassword: '',
        keySetupMode: '',
        keySetupError: '',
        keySetupBusy: false,
        keyGenerated: false,
        recipientPublicKey: null,
        recipientKeyVersion: 0,
        sharedKey: null,
        sharedKeyReady: false,
        isDerivingKey: false,
        isWaitingForContactKey: false,
        keyPairPromise: null,
        messages: [],
        newMessage: '',
        drafts: {},
        loadingHistory: false,
        sending: false,
        unreadCounts: {},
        pendingIncomingMessages: {},
        selectionRequestId: 0,
        chatError: '',
        sendError: '',
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content,

        async initChat() {
            try {
                await this.ensureLocalKeyPair();
            } catch (err) {
                console.error('Could not initialize local encryption keys:', err);
                this.chatError = 'Could not initialize encryption. Select a contact to retry.';
            }

            // Listen to the authenticated user's private Echo channel.
            if (window.Echo) {
                const handleEvent = async (e) => {
                    await this.handleIncomingMessage(e);
                };

                window.Echo.private(`chat.${this.authId}`)
                    .listen('.MessageSent', handleEvent)
                    .listen('.CleanupUpdated', (event) => {
                        if (event.status === 'approved' && this.selectedUser &&
                            [Number(event.first_user_id), Number(event.second_user_id)].includes(Number(this.selectedUser.id))) {
                            this.messages = [];
                            this.loadChatHistory();
                        }
                    })
                    .listen('.BlockStatusUpdated', (event) => {
                        const blockerId = Number(event.blocker_id);
                        const blockedId = Number(event.blocked_id);
                        const contactId = blockerId === Number(this.authId) ? blockedId : blockerId;
                        const contact = this.users.find((user) => Number(user.id) === contactId);

                        if (!contact) return;

                        if (blockerId === Number(this.authId)) {
                            contact.blocked_by_me = event.blocked;
                        } else {
                            contact.blocked_me = event.blocked;
                        }

                        if (this.selectedUser && Number(this.selectedUser.id) === contactId) {
                            this.selectedUser.blocked_by_me = contact.blocked_by_me;
                            this.selectedUser.blocked_me = contact.blocked_me;
                        }
                    });
            }
        },

        async ensureLocalKeyPair() {
            if (!window.E2EE) throw new Error('The E2EE module is unavailable.');
            if (this.identityInitialized) return;

            const response = await fetch('/user/key-backup', { headers: { 'Accept': 'application/json' } });
            if (!response.ok) throw new Error('Could not check for an encrypted key backup.');
            this.keyBackupInfo = await response.json();
            const loginPassword = window.consumeChatLoginPassword?.() ?? null;
            const currentVersion = Number(this.keyBackupInfo.public_key_version || (this.keyBackupInfo.public_key ? 1 : 0));

            const localVersion = currentVersion || 1;
            const localKey = await window.E2EE.getUserKeyPair(this.authId, localVersion);
            if (localKey && this.keyBackupInfo.has_backup) {
                this.useLocalIdentity(localKey, localVersion);
                this.identityInitialized = true;
                return;
            }

            if (this.keyBackupInfo.has_backup) {
                this.identityVersion = currentVersion;
                if (loginPassword) {
                    try {
                        await this.restoreKeyWithPassword(loginPassword);
                        this.identityInitialized = true;
                        return;
                    } catch (error) {
                        console.warn('Could not unlock the chat key with the login password:', error);
                        this.keySetupError = 'Your sign-in password could not unlock the chat key. Enter the current account password to restore it.';
                    }
                }
                this.keySetupMode = 'restore';
                this.identityInitialized = true;
                return;
            }

            this.identityVersion = Math.max(currentVersion, localKey ? localVersion : 0) + 1;
            this.pendingKeyPair = await window.E2EE.generatePortableKeyPair();
            this.recoveryPhrase = await window.E2EE.generateRecoveryPhrase();
            this.keySetupMode = 'create';
            this.identityInitialized = true;
        },

        useLocalIdentity(keys, version) {
            this.keyPair = keys.keyPair;
            this.myPublicKeyJwk = keys.publicKeyJwk;
            this.identityVersion = version;
            this.keyGenerated = true;
        },

        async restoreKeyWithPassword(password) {
            const restored = await window.E2EE.restoreKeyBackup(
                this.keyBackupInfo.password_key_backup,
                password,
            );
            const expected = JSON.parse(this.keyBackupInfo.public_key);
            if (restored.publicKeyJwk.x !== expected.x || restored.publicKeyJwk.y !== expected.y) {
                throw new Error('The backup does not match the published public key.');
            }

            await window.E2EE.storeUserKeyPair(this.authId, restored, this.identityVersion);
            this.useLocalIdentity(restored, this.identityVersion);
        },

        async savePortableKey() {
            if (!this.pendingKeyPair || !this.recoveryPhraseSaved) return;
            this.keySetupBusy = true;
            this.keySetupError = '';

            try {
                const passwordBackup = await window.E2EE.encryptKeyBackup(this.pendingKeyPair, this.keyPassword);
                const recoveryBackup = await window.E2EE.encryptKeyBackup(this.pendingKeyPair, this.recoveryPhrase, true);
                const response = await fetch('/user/key-backup', {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        public_key: JSON.stringify(this.pendingKeyPair.publicKeyJwk),
                        public_key_version: this.identityVersion,
                        password_key_backup: passwordBackup,
                        recovery_key_backup: recoveryBackup.envelope,
                        recovery_key_verifier: recoveryBackup.verifier,
                    }),
                });
                if (!response.ok) throw new Error('The encrypted key backup could not be saved.');

                await window.E2EE.storeUserKeyPair(this.authId, this.pendingKeyPair, this.identityVersion);
                this.useLocalIdentity(this.pendingKeyPair, this.identityVersion);
                this.pendingKeyPair = null;
                this.keyPassword = '';
                this.recoveryPhrase = '';
                this.recoveryPhraseSaved = false;
                this.keySetupMode = '';
            } catch (error) {
                console.error('Could not save portable chat keys:', error);
                this.keySetupError = 'Could not save the encrypted key backup. Please try again.';
            } finally {
                this.keySetupBusy = false;
            }
        },

        async restorePortableKey() {
            this.keySetupBusy = true;
            this.keySetupError = '';

            try {
                await this.restoreKeyWithPassword(this.keyPassword);
                this.keyPassword = '';
                this.keySetupMode = '';
            } catch (error) {
                console.error('Could not restore portable chat key:', error);
                this.keySetupError = 'That password did not unlock this chat key. Check it and try again.';
            } finally {
                this.keySetupBusy = false;
            }
        },

        async fetchContactPublicKey(contactId, selectionRequestId) {
            this.isWaitingForContactKey = false;

            for (let attempt = 0; attempt < 30; attempt++) {
                if (selectionRequestId !== this.selectionRequestId) return null;

                const response = await fetch(`/users/${contactId}/public-key`, {
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' },
                });
                if (!response.ok) {
                    throw new Error('Could not load the contact public key.');
                }

                const { public_key: publicKey, version: publicKeyVersion } = await response.json();
                if (publicKey) return { publicKey, version: Number(publicKeyVersion) };

                this.isWaitingForContactKey = true;
                await new Promise((resolve) => window.setTimeout(resolve, 1000));
            }

            return null;
        },

        async selectUser(user) {
            const selectionRequestId = ++this.selectionRequestId;
            if (this.selectedUser && this.newMessage.trim()) {
                this.drafts[this.selectedUser.id] = this.newMessage;
            }

            this.selectedUser = user;
            this.newMessage = this.drafts[user.id] ?? '';
            delete this.unreadCounts[user.id];
            this.messages = [];
            this.sharedKey = null;
            this.sharedKeyReady = false;
            this.recipientKeyVersion = 0;
            this.isDerivingKey = true;
            this.isWaitingForContactKey = false;
            this.loadingHistory = true;
            this.chatError = '';
            this.sendError = '';

            try {
                await this.ensureLocalKeyPair();
                if (!this.keyPair) throw new Error('Restore or set up your chat key to continue.');
                const targetPubKeyStr = await this.fetchContactPublicKey(user.id, selectionRequestId);

                if (selectionRequestId === this.selectionRequestId && targetPubKeyStr) {
                    const recipientJwk = JSON.parse(targetPubKeyStr.publicKey);
                    const recipientPublicKey = await window.E2EE.importPublicKey(recipientJwk);
                    const sharedKey = await window.E2EE.deriveSharedKey(this.keyPair.privateKey,
                        recipientPublicKey);

                    if (selectionRequestId !== this.selectionRequestId) return;

                    this.recipientPublicKey = recipientPublicKey;
                    this.recipientKeyVersion = targetPubKeyStr.version;
                    this.sharedKey = sharedKey;
                    this.sharedKeyReady = true;
                } else if (selectionRequestId === this.selectionRequestId) {
                    this.chatError = 'This contact has not published an encryption key yet. Keep this chat open while they initialize encryption.';
                }
            } catch (err) {
                console.error('Error deriving shared key:', err);
                if (selectionRequestId === this.selectionRequestId) {
                    this.chatError = 'Could not establish encrypted chat with this contact.';
                }
            } finally {
                if (selectionRequestId === this.selectionRequestId) {
                    this.isDerivingKey = false;
                    this.isWaitingForContactKey = false;
                }
            }

            if (selectionRequestId !== this.selectionRequestId) return;

            const pendingMessages = this.pendingIncomingMessages[user.id] ?? [];
            delete this.pendingIncomingMessages[user.id];
            for (const message of pendingMessages) {
                await this.handleIncomingMessage(message);
            }

            // Fetch chat history
            await this.loadChatHistory(user.id, selectionRequestId);
        },

        async updateContacts({ users, addedContactId }) {
            this.users = users;
            const addedContact = this.users.find((contact) => Number(contact.id) === Number(addedContactId));

            if (addedContact) {
                await this.selectUser({ ...addedContact, public_key: null });
            }
        },

        async loadChatHistory(selectedUserId = this.selectedUser?.id, selectionRequestId = this
            .selectionRequestId) {
            if (!selectedUserId) return;
            const sharedKey = this.sharedKey;
            this.loadingHistory = true;

            try {
                const res = await fetch(`/messages/${selectedUserId}`, {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                if (!res.ok) {
                    throw new Error('Could not load chat history.');
                }

                const history = await res.json();
                if (!Array.isArray(history)) {
                    throw new Error('The chat history response was invalid.');
                }
                if (selectionRequestId !== this.selectionRequestId || this.selectedUser?.id !==
                    selectedUserId) return;
                if (sharedKey) {
                    this.chatError = '';
                }

                const decryptedMessages = [];
                for (const msg of history) {
                    let decryptedText = '[Message unavailable on this browser]';
                    if (window.E2EE) {
                        try {
                            const messageKey = await this.sharedKeyForMessage(msg);
                            if (messageKey) decryptedText = await window.E2EE.decryptMessage(messageKey, msg.encrypted_payload, msg.iv);
                        } catch (e) {
                            console.error('Failed to decrypt message:', e);
                        }
                    }
                    decryptedMessages.push({
                        id: msg.id,
                        sender_id: msg.sender_id,
                        receiver_id: msg.receiver_id,
                        text: decryptedText,
                        created_at: msg.created_at
                    });
                }
                if (selectionRequestId !== this.selectionRequestId || this.selectedUser?.id !==
                    selectedUserId) return;

                const messagesById = new Map(
                    [...decryptedMessages, ...this.messages].map((message) => [message.id,
                        message
                    ]),
                );
                this.messages = [...messagesById.values()].sort(
                    (first, second) => new Date(first.created_at) - new Date(second.created_at),
                );
                this.scrollToBottom();
            } catch (err) {
                console.error('Error loading history:', err);
                if (selectionRequestId === this.selectionRequestId) {
                    this.chatError =
                        'Could not load chat history. Please try selecting the contact again.';
                }
            } finally {
                if (selectionRequestId === this.selectionRequestId && this.selectedUser?.id ===
                    selectedUserId) {
                    this.loadingHistory = false;
                }
            }
        },

        async sharedKeyForMessage(message) {
            const sentByMe = Number(message.sender_id) === Number(this.authId);
            const ownVersion = Number((sentByMe ? message.sender_key_version : message.receiver_key_version) || 1);
            const remoteVersion = Number((sentByMe ? message.receiver_key_version : message.sender_key_version) || 1);
            const ownKeys = await window.E2EE.getUserKeyPair(this.authId, ownVersion);
            if (!ownKeys) return null;

            const response = await fetch(`/users/${this.selectedUser.id}/public-key?version=${remoteVersion}`, {
                cache: 'no-store',
                headers: { 'Accept': 'application/json' },
            });
            if (!response.ok) return null;
            const { public_key: publicKey } = await response.json();
            if (!publicKey) return null;

            return window.E2EE.deriveSharedKey(ownKeys.keyPair.privateKey, await window.E2EE.importPublicKey(JSON.parse(publicKey)));
        },

        async sendMessage() {
            if (!this.newMessage.trim() || !this.selectedUser || !this.sharedKey || this.sending)
                return;

            const text = this.newMessage.trim();
            const recipient = this.selectedUser;
            const sharedKey = this.sharedKey;
            delete this.drafts[recipient.id];
            this.newMessage = '';
            this.sending = true;
            this.sendError = '';

            try {
                // Encrypt payload locally
                const {
                    encryptedPayload,
                    iv
                } = await window.E2EE.encryptMessage(sharedKey, text);

                // Send to backend
                const res = await fetch('/messages', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        receiver_id: recipient.id,
                        sender_key_version: this.identityVersion,
                        receiver_key_version: this.recipientKeyVersion,
                        encrypted_payload: encryptedPayload,
                        iv: iv
                    })
                });

                if (!res.ok) {
                    throw new Error('The encrypted message could not be sent.');
                }

                const savedMsg = await res.json();
                if (this.selectedUser?.id === recipient.id) {
                    this.messages.push({
                        id: savedMsg.id,
                        sender_id: this.authId,
                        receiver_id: recipient.id,
                        text: text,
                        created_at: savedMsg.created_at
                    });
                    this.scrollToBottom();
                }
            } catch (err) {
                console.error('Failed to send message:', err);
                this.drafts[recipient.id] = text;
                if (this.selectedUser?.id === recipient.id && !this.newMessage) {
                    this.newMessage = this.drafts[recipient.id];
                    delete this.drafts[recipient.id];
                    this.sendError = 'Message was not sent. Your draft has been restored.';
                }
            } finally {
                this.sending = false;
            }
        },

        async handleIncomingMessage(e) {
            if (e.sender && !this.users.some((user) => Number(user.id) === Number(e.sender.id))) {
                this.users.push({
                    ...e.sender,
                    display_name: e.sender.email,
                    blocked_by_me: false,
                    blocked_me: false,
                });
            }

            // Deduplicate if message was already added
            if (this.messages.some(m => m.id === e.id)) {
                return;
            }

            // If the incoming message is from currently selected user
            if (this.selectedUser && e.sender_id === this.selectedUser.id) {
                if (!this.sharedKey) {
                    const pendingMessages = this.pendingIncomingMessages[e.sender_id] ?? [];
                    pendingMessages.push(e);
                    this.pendingIncomingMessages[e.sender_id] = pendingMessages;

                    return;
                }

                let text = '[Message unavailable on this browser]';
                if (window.E2EE) {
                    try {
                        const messageKey = await this.sharedKeyForMessage(e);
                        if (messageKey) text = await window.E2EE.decryptMessage(messageKey, e.encrypted_payload, e.iv);
                    } catch (err) {
                        console.error('Failed to decrypt incoming message:', err);
                    }
                }
                this.messages.push({
                    id: e.id,
                    sender_id: e.sender_id,
                    receiver_id: e.receiver_id,
                    text: text,
                    created_at: e.created_at
                });
                this.scrollToBottom();
            } else if (e.sender_id !== this.authId) {
                // Increment unread count for the sender
                this.unreadCounts[e.sender_id] = (this.unreadCounts[e.sender_id] || 0) + 1;
            }
        },

        formatTime(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        scrollToBottom() {
            this.$nextTick(() => {
                const box = this.$refs.messageBox;
                if (box) {
                    box.scrollTop = box.scrollHeight;
                }
            });
        }
    }));
</script>
