<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Chat')] class extends Component {
    /**
     * Get users available to chat with.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function getUsersProperty()
    {
        return User::where('id', '!=', Auth::id())
            ->where('role', 'user')
            ->select(['id', 'name', 'email', 'public_key'])
            ->get();
    }
}; ?>

<div
    x-data="chatComponent({
        authId: {{ auth()->id() }},
        usersList: {{ Js::from($this->users) }}
    })"
    x-init="initChat()"
    class="flex h-[calc(100vh-6rem)] w-full overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-zinc-900 shadow-sm"
>
    <!-- Left Sidebar: Contact List -->
    <div class="w-1/3 min-w-[250px] max-w-[350px] flex flex-col border-r border-neutral-200 dark:border-neutral-700 bg-zinc-50 dark:bg-zinc-900/50">
        <div class="p-4 border-b border-neutral-200 dark:border-neutral-700">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Messages') }}
            </h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 flex items-center gap-1 mt-1" x-show="keyGenerated">
                <span class="inline-block size-2 rounded-full bg-emerald-500"></span>
                <span>{{ __('E2EE Enabled (ECDH / AES-256-GCM)') }}</span>
            </p>
        </div>

        <div class="flex-1 overflow-y-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <template x-for="user in users" :key="user.id">
                <button
                    type="button"
                    @click="selectUser(user)"
                    :class="selectedUser?.id === user.id ? 'bg-neutral-200/70 dark:bg-neutral-800' : 'hover:bg-neutral-100 dark:hover:bg-neutral-800/50'"
                    class="w-full p-4 flex items-center gap-3 text-left transition-colors cursor-pointer"
                >
                    <div class="relative">
                        <div class="size-10 rounded-full bg-indigo-500 text-white flex items-center justify-center font-semibold text-sm">
                            <span x-text="user.name.substring(0, 2).toUpperCase()"></span>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-neutral-900 dark:text-neutral-100 truncate" x-text="user.name"></p>
                        </div>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 truncate" x-text="user.email"></p>
                    </div>
                </button>
            </template>

            <div x-show="users.length === 0" class="p-4 text-center text-sm text-neutral-500 dark:text-neutral-400">
                {{ __('No other contacts found.') }}
            </div>
        </div>
    </div>

    <!-- Right Chat Window -->
    <div class="flex-1 flex flex-col bg-white dark:bg-zinc-900">
        <template x-if="selectedUser">
            <div class="flex-1 flex flex-col h-full overflow-hidden">
                <!-- Chat Header -->
                <div class="p-4 border-b border-neutral-200 dark:border-neutral-700 flex items-center justify-between bg-zinc-50/50 dark:bg-zinc-900/50">
                    <div class="flex items-center gap-3">
                        <div class="size-9 rounded-full bg-indigo-500 text-white flex items-center justify-center font-semibold text-sm">
                            <span x-text="selectedUser.name.substring(0, 2).toUpperCase()"></span>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100" x-text="selectedUser.name"></h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400" x-text="selectedUser.email"></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span x-show="isDerivingKey" class="text-xs text-amber-500 animate-pulse">{{ __('Deriving Shared Secret...') }}</span>
                        <span x-show="sharedKeyReady" class="text-xs px-2 py-1 rounded bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-medium">
                            🔒 {{ __('Encrypted') }}
                        </span>
                    </div>
                </div>

                <!-- Messages Stream -->
                <div x-ref="messageBox" class="flex-1 overflow-y-auto p-4 space-y-3">
                    <template x-for="msg in messages" :key="msg.id || msg.temp_id">
                        <div
                            :class="msg.sender_id === authId ? 'justify-end' : 'justify-start'"
                            class="flex w-full"
                        >
                            <div
                                :class="msg.sender_id === authId
                                    ? 'bg-indigo-600 text-white rounded-l-lg rounded-tr-lg'
                                    : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 rounded-r-lg rounded-tl-lg border border-neutral-200 dark:border-neutral-700'"
                                class="max-w-[70%] px-4 py-2 text-sm shadow-sm space-y-1"
                            >
                                <p class="break-words whitespace-pre-wrap" x-text="msg.text"></p>
                                <div
                                    :class="msg.sender_id === authId ? 'text-indigo-200' : 'text-neutral-400'"
                                    class="text-[10px] text-right"
                                >
                                    <span x-text="formatTime(msg.created_at)"></span>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div x-show="loadingHistory" class="text-center py-4 text-xs text-neutral-400">
                        {{ __('Loading and decrypting message history...') }}
                    </div>

                    <div x-show="!loadingHistory && messages.length === 0" class="text-center py-8 text-sm text-neutral-400">
                        {{ __('No messages yet. Say hello!') }}
                    </div>
                </div>

                <!-- Input Footer -->
                <form @submit.prevent="sendMessage()" class="p-3 border-t border-neutral-200 dark:border-neutral-700 flex items-center gap-2 bg-zinc-50/50 dark:bg-zinc-900/50">
                    <input
                        type="text"
                        x-model="newMessage"
                        :disabled="!sharedKeyReady || sending"
                        placeholder="{{ __('Type an encrypted message...') }}"
                        class="flex-1 rounded-lg border border-neutral-300 dark:border-neutral-700 bg-white dark:bg-zinc-800 px-4 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50"
                    />
                    <button
                        type="submit"
                        :disabled="!sharedKeyReady || !newMessage.trim() || sending"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50 flex items-center gap-1 cursor-pointer"
                    >
                        <span x-text="sending ? 'Sending...' : 'Send'"></span>
                    </button>
                </form>
            </div>
        </template>

        <template x-if="!selectedUser">
            <div class="flex-1 flex flex-col items-center justify-center p-8 text-center text-neutral-400">
                <svg class="size-16 mb-4 stroke-1 text-neutral-300 dark:text-neutral-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a.75.75 0 01-1.054-.916 4.38 4.38 0 00.32-1.34A8.253 8.253 0 013 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                </svg>
                <h3 class="text-base font-medium text-neutral-700 dark:text-neutral-300">{{ __('Select a contact to start chatting') }}</h3>
                <p class="text-xs mt-1 max-w-sm">{{ __('Messages are end-to-end encrypted. Plaintext messages and private keys never leave your browser.') }}</p>
            </div>
        </template>
    </div>
</div>

<script>
function chatComponent({ authId, usersList }) {
    return {
        authId: authId,
        users: usersList,
        selectedUser: null,
        keyPair: null,
        myPublicKeyJwk: null,
        keyGenerated: false,
        recipientPublicKey: null,
        sharedKey: null,
        sharedKeyReady: false,
        isDerivingKey: false,
        messages: [],
        newMessage: '',
        loadingHistory: false,
        sending: false,
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content,

        async initChat() {
            // 1. Get or generate local ECDH key pair (persisted in IndexedDB)
            if (window.E2EE) {
                const { keyPair, publicKeyJwk, isNew } = await window.E2EE.getOrGenerateUserKeyPair(this.authId);
                this.keyPair = keyPair;
                this.myPublicKeyJwk = publicKeyJwk;
                this.keyGenerated = true;

                // Send public key JWK to backend if new key pair was generated
                if (isNew) {
                    await fetch('/user/public-key', {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            public_key: JSON.stringify(publicKeyJwk),
                        })
                    });
                }
            }

            // 2. Listen to private Echo channel for incoming messages
            if (window.Echo) {
                window.Echo.private(`chat.${this.authId}`)
                    .listen('MessageSent', async (e) => {
                        await this.handleIncomingMessage(e);
                    });
            }
        },

        async selectUser(user) {
            this.selectedUser = user;
            this.messages = [];
            this.sharedKey = null;
            this.sharedKeyReady = false;
            this.isDerivingKey = true;
            this.loadingHistory = true;

            try {
                // Fetch latest public key of user if missing
                let targetPubKeyStr = user.public_key;
                if (!targetPubKeyStr) {
                    const res = await fetch(`/users/${user.id}/public-key`);
                    const data = await res.json();
                    targetPubKeyStr = data.public_key;
                }

                if (targetPubKeyStr && window.E2EE) {
                    const recipientJwk = JSON.parse(targetPubKeyStr);
                    this.recipientPublicKey = await window.E2EE.importPublicKey(recipientJwk);
                    this.sharedKey = await window.E2EE.deriveSharedKey(this.keyPair.privateKey, this.recipientPublicKey);
                    this.sharedKeyReady = true;
                }
            } catch (err) {
                console.error('Error deriving shared key:', err);
            } finally {
                this.isDerivingKey = false;
            }

            // Fetch chat history
            await this.loadChatHistory();
        },

        async loadChatHistory() {
            if (!this.selectedUser) return;
            this.loadingHistory = true;

            try {
                const res = await fetch(`/messages/${this.selectedUser.id}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const history = await res.json();

                const decryptedMessages = [];
                for (const msg of history) {
                    let decryptedText = '[Encrypted Message]';
                    if (this.sharedKey && window.E2EE) {
                        try {
                            decryptedText = await window.E2EE.decryptMessage(this.sharedKey, msg.encrypted_payload, msg.iv);
                        } catch (e) {
                            console.error('Failed to decrypt message:', e);
                            decryptedText = '[Unable to decrypt message]';
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
                this.messages = decryptedMessages;
                this.scrollToBottom();
            } catch (err) {
                console.error('Error loading history:', err);
            } finally {
                this.loadingHistory = false;
            }
        },

        async sendMessage() {
            if (!this.newMessage.trim() || !this.selectedUser || !this.sharedKey || this.sending) return;

            const text = this.newMessage.trim();
            this.newMessage = '';
            this.sending = true;

            try {
                // Encrypt payload locally
                const { encryptedPayload, iv } = await window.E2EE.encryptMessage(this.sharedKey, text);

                // Send to backend
                const res = await fetch('/messages', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        receiver_id: this.selectedUser.id,
                        encrypted_payload: encryptedPayload,
                        iv: iv
                    })
                });

                if (res.ok) {
                    const savedMsg = await res.json();
                    this.messages.push({
                        id: savedMsg.id,
                        sender_id: this.authId,
                        receiver_id: this.selectedUser.id,
                        text: text,
                        created_at: savedMsg.created_at
                    });
                    this.scrollToBottom();
                }
            } catch (err) {
                console.error('Failed to send message:', err);
            } finally {
                this.sending = false;
            }
        },

        async handleIncomingMessage(e) {
            // If the incoming message is from currently selected user
            if (this.selectedUser && e.sender_id === this.selectedUser.id) {
                let text = '[Encrypted Message]';
                if (this.sharedKey && window.E2EE) {
                    try {
                        text = await window.E2EE.decryptMessage(this.sharedKey, e.encrypted_payload, e.iv);
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
            }
        },

        formatTime(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },

        scrollToBottom() {
            this.$nextTick(() => {
                const box = this.$refs.messageBox;
                if (box) {
                    box.scrollTop = box.scrollHeight;
                }
            });
        }
    };
}
</script>