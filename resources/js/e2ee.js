import { argon2id } from 'hash-wasm';
import { generateMnemonic, validateMnemonic } from '@scure/bip39';
import { wordlist } from '@scure/bip39/wordlists/english.js';

const DB_NAME = 'E2EEChatDB';
const DB_VERSION = 2;
const STORE_NAME = 'keys';

function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) db.createObjectStore(STORE_NAME);
        };
        request.onsuccess = (event) => resolve(event.target.result);
        request.onerror = (event) => reject(event.target.error);
    });
}

function storageKey(userId, version) {
    return `${String(userId)}:${version}`;
}

async function toKeyPairData(record) {
    return {
        keyPair: {
            privateKey: record.privateKey,
            publicKey: record.publicKey ?? await importPublicKey(record.publicKeyJwk),
        },
        publicKeyJwk: record.publicKeyJwk,
    };
}

export async function storeUserKeyPair(userId, data, version = 1) {
    const privateKey = data.privateKeyJwk
        ? await crypto.subtle.importKey(
            'jwk', JSON.parse(JSON.stringify(data.privateKeyJwk)),
            { name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveKey'],
        )
        : data.keyPair.privateKey;
    const publicKeyJwk = JSON.parse(JSON.stringify(data.publicKeyJwk));
    const db = await openDB();
    return new Promise((resolve, reject) => {
        let tx;
        try {
            tx = db.transaction(STORE_NAME, 'readwrite');
            tx.objectStore(STORE_NAME).put({
                userId: String(userId),
                version,
                privateKey,
                publicKeyJwk,
            }, storageKey(userId, version));
        } catch (error) {
            if (error.name === 'DataCloneError') {
                console.warn('This browser cannot cache the chat key in IndexedDB.');
                resolve(false);
                return;
            }
            reject(error);
            return;
        }
        tx.oncomplete = () => resolve(true);
        tx.onabort = () => {
            if (tx.error?.name === 'DataCloneError') {
                console.warn('This browser cannot cache the chat key in IndexedDB.');
                resolve(false);
                return;
            }
            reject(tx.error);
        };
        tx.onerror = () => {
            if (tx.error?.name === 'DataCloneError') {
                console.warn('This browser cannot cache the chat key in IndexedDB.');
            }
        };
    });
}

export async function getUserKeyPair(userId, version = 1) {
    try {
        const db = await openDB();
        return await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const store = tx.objectStore(STORE_NAME);
            const request = store.get(storageKey(userId, version));
            request.onsuccess = () => {
                if (request.result) {
                    toKeyPairData(request.result).then(resolve, reject);
                    return;
                }
                if (version !== 1) return resolve(null);

                const legacyRequest = store.get(String(userId));
                legacyRequest.onsuccess = () => {
                    if (!legacyRequest.result) {
                        resolve(null);
                        return;
                    }
                    toKeyPairData(legacyRequest.result).then(resolve, reject);
                };
                legacyRequest.onerror = () => reject(legacyRequest.error);
            };
            request.onerror = () => reject(request.error);
        });
    } catch (error) {
        console.error('Failed to retrieve key pair from IndexedDB:', error);
        return null;
    }
}

export async function generateRecoveryPhrase() {
    return generateMnemonic(wordlist, 256);
}

export function isValidRecoveryPhrase(phrase) {
    return validateMnemonic(normalizePhrase(phrase), wordlist);
}

function normalizePhrase(phrase) {
    return phrase.trim().toLowerCase().split(/\s+/).join(' ');
}

export async function generatePortableKeyPair() {
    const exportablePair = await crypto.subtle.generateKey(
        { name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveKey'],
    );
    const privateKeyJwk = await crypto.subtle.exportKey('jwk', exportablePair.privateKey);
    const publicKeyJwk = await crypto.subtle.exportKey('jwk', exportablePair.publicKey);
    const keyPair = {
        privateKey: await crypto.subtle.importKey(
            'jwk', privateKeyJwk, { name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveKey'],
        ),
        publicKey: exportablePair.publicKey,
    };

    return { keyPair, privateKeyJwk, publicKeyJwk };
}

async function deriveBackupKey(secret, salt, recovery = false) {
    const password = recovery ? normalizePhrase(secret) : secret;
    const rawKey = await argon2id({
        password,
        salt,
        parallelism: 1,
        iterations: 3,
        memorySize: 65536,
        hashLength: 32,
        outputType: 'binary',
    });

    return {
        rawKey,
        cryptoKey: await crypto.subtle.importKey('raw', rawKey, 'AES-GCM', false, ['encrypt', 'decrypt']),
    };
}

async function recoveryVerifier(rawKey) {
    const prefix = new TextEncoder().encode('chat-recovery-verifier-v1');
    const data = new Uint8Array(rawKey.length + prefix.length);
    data.set(rawKey);
    data.set(prefix, rawKey.length);
    return arrayBufferToBase64(await crypto.subtle.digest('SHA-256', data));
}

export async function encryptKeyBackup(keyPairData, secret, recovery = false) {
    const salt = crypto.getRandomValues(new Uint8Array(16));
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const derived = await deriveBackupKey(secret, salt, recovery);
    const payload = JSON.stringify({
        privateKeyJwk: keyPairData.privateKeyJwk,
        publicKeyJwk: keyPairData.publicKeyJwk,
    });
    const ciphertext = await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv }, derived.cryptoKey, new TextEncoder().encode(payload),
    );
    const envelope = {
        salt: arrayBufferToBase64(salt),
        iv: arrayBufferToBase64(iv),
        ciphertext: arrayBufferToBase64(ciphertext),
    };

    return recovery ? { envelope, verifier: await recoveryVerifier(derived.rawKey) } : envelope;
}

export async function restoreKeyBackup(envelope, secret, recovery = false) {
    if (!envelope || !envelope.salt || !envelope.iv || !envelope.ciphertext) {
        throw new Error('The key backup is incomplete.');
    }
    if (recovery && !isValidRecoveryPhrase(secret)) {
        throw new Error('Enter the complete 24-word recovery phrase.');
    }

    const derived = await deriveBackupKey(secret, new Uint8Array(base64ToArrayBuffer(envelope.salt)), recovery);
    const plaintext = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: base64ToArrayBuffer(envelope.iv) },
        derived.cryptoKey,
        base64ToArrayBuffer(envelope.ciphertext),
    );
    const restored = JSON.parse(new TextDecoder().decode(plaintext));
    const keyPair = {
        privateKey: await crypto.subtle.importKey(
            'jwk', restored.privateKeyJwk,
            { name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveKey'],
        ),
        publicKey: await crypto.subtle.importKey(
            'jwk', restored.publicKeyJwk,
            { name: 'ECDH', namedCurve: 'P-256' }, true, [],
        ),
    };

    return {
        keyPair,
        privateKeyJwk: restored.privateKeyJwk,
        publicKeyJwk: restored.publicKeyJwk,
        verifier: recovery ? await recoveryVerifier(derived.rawKey) : null,
    };
}

export async function verifyRecoveryProof(envelope, phrase) {
    const derived = await deriveBackupKey(phrase, new Uint8Array(base64ToArrayBuffer(envelope.salt)), true);
    return recoveryVerifier(derived.rawKey);
}

export async function importPublicKey(jwk) {
    return crypto.subtle.importKey('jwk', jwk, { name: 'ECDH', namedCurve: 'P-256' }, true, []);
}

export async function deriveSharedKey(privateKey, theirPublicKey) {
    return crypto.subtle.deriveKey(
        { name: 'ECDH', public: theirPublicKey }, privateKey,
        { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'],
    );
}

export async function encryptMessage(sharedKey, plaintext) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ciphertext = await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv }, sharedKey, new TextEncoder().encode(plaintext),
    );
    return { encryptedPayload: arrayBufferToBase64(ciphertext), iv: arrayBufferToBase64(iv) };
}

export async function decryptMessage(sharedKey, encryptedPayload, iv) {
    const plaintext = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: base64ToArrayBuffer(iv) },
        sharedKey,
        base64ToArrayBuffer(encryptedPayload),
    );
    return new TextDecoder().decode(plaintext);
}

function arrayBufferToBase64(buffer) {
    return btoa(String.fromCharCode(...new Uint8Array(buffer)));
}

function base64ToArrayBuffer(base64) {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
    return bytes.buffer;
}
