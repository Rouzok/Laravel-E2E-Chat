/**
 * E2EE Chat — End-to-End Encryption using Web Crypto API (ECDH + AES-256-GCM)
 *
 * The backend NEVER sees plaintext messages or private keys.
 * Keys are generated per-session and the private key is stored as non-extractable
 * in memory (not persisted to IndexedDB for simplicity; add persistence as needed).
 */

const DB_NAME = 'E2EEChatDB';
const DB_VERSION = 1;
const STORE_NAME = 'keys';

/**
 * Open IndexedDB database connection.
 * @returns {Promise<IDBDatabase>}
 */
function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME);
            }
        };
        request.onsuccess = (e) => resolve(e.target.result);
        request.onerror = (e) => reject(e.target.error);
    });
}

/**
 * Store user's ECDH key pair securely in IndexedDB.
 * The private key remains non-extractable in browser storage.
 *
 * @param {string|number} userId
 * @param {{keyPair: CryptoKeyPair, publicKeyJwk: JsonWebKey}} data
 * @returns {Promise<void>}
 */
export async function storeUserKeyPair(userId, data) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        const req = store.put({
            userId: String(userId),
            privateKey: data.keyPair.privateKey,
            publicKey: data.keyPair.publicKey,
            publicKeyJwk: data.publicKeyJwk,
        }, String(userId));
        req.onsuccess = () => resolve();
        req.onerror = (e) => reject(e.target.error);
    });
}

/**
 * Retrieve user's key pair from IndexedDB.
 *
 * @param {string|number} userId
 * @returns {Promise<{keyPair: CryptoKeyPair, publicKeyJwk: JsonWebKey}|null>}
 */
export async function getUserKeyPair(userId) {
    try {
        const db = await openDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const store = tx.objectStore(STORE_NAME);
            const req = store.get(String(userId));
            req.onsuccess = (e) => {
                const res = e.target.result;
                if (!res) return resolve(null);
                resolve({
                    keyPair: {
                        privateKey: res.privateKey,
                        publicKey: res.publicKey,
                    },
                    publicKeyJwk: res.publicKeyJwk,
                });
            };
            req.onerror = (e) => reject(e.target.error);
        });
    } catch (e) {
        console.error('Failed to retrieve key pair from IndexedDB:', e);
        return null;
    }
}

/**
 * Get existing ECDH key pair from IndexedDB or generate and persist a new one.
 *
 * @param {string|number} userId
 * @returns {Promise<{keyPair: CryptoKeyPair, publicKeyJwk: JsonWebKey, isNew: boolean}>}
 */
export async function getOrGenerateUserKeyPair(userId) {
    const existing = await getUserKeyPair(userId);
    if (existing) {
        return { ...existing, isNew: false };
    }

    const newKeys = await generateKeyPair();
    await storeUserKeyPair(userId, newKeys);
    return { ...newKeys, isNew: true };
}

/**
 * Generate an ECDH key pair.
 * The private key is non-extractable (stays in browser memory/WebCrypto).
 *
 * @returns {Promise<{keyPair: CryptoKeyPair, publicKeyJwk: JsonWebKey}>}
 */
export async function generateKeyPair() {
    const keyPair = await crypto.subtle.generateKey(
        { name: 'ECDH', namedCurve: 'P-256' },
        false, // private key is non-extractable
        ['deriveKey'],
    );

    const publicKeyJwk = await crypto.subtle.exportKey('jwk', keyPair.publicKey);

    return { keyPair, publicKeyJwk };
}

/**
 * Import a recipient's raw public key (JWK) for ECDH.
 *
 * @param {JsonWebKey} jwk
 * @returns {Promise<CryptoKey>}
 */
export async function importPublicKey(jwk) {
    return crypto.subtle.importKey(
        'jwk',
        jwk,
        { name: 'ECDH', namedCurve: 'P-256' },
        true,
        [],
    );
}

/**
 * Derive a shared AES-256-GCM key from our private key and their public key.
 *
 * @param {CryptoKey} privateKey
 * @param {CryptoKey} theirPublicKey
 * @returns {Promise<CryptoKey>}
 */
export async function deriveSharedKey(privateKey, theirPublicKey) {
    return crypto.subtle.deriveKey(
        { name: 'ECDH', public: theirPublicKey },
        privateKey,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
}

/**
 * Encrypt plaintext using the shared AES-256-GCM key.
 *
 * @param {CryptoKey} sharedKey
 * @param {string} plaintext
 * @returns {Promise<{encryptedPayload: string, iv: string}>} Base64-encoded ciphertext and IV
 */
export async function encryptMessage(sharedKey, plaintext) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const encoded = new TextEncoder().encode(plaintext);

    const ciphertext = await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv },
        sharedKey,
        encoded,
    );

    return {
        encryptedPayload: arrayBufferToBase64(ciphertext),
        iv: arrayBufferToBase64(iv),
    };
}

/**
 * Decrypt a Base64-encoded ciphertext using the shared AES-256-GCM key.
 *
 * @param {CryptoKey} sharedKey
 * @param {string} encryptedPayload  Base64-encoded ciphertext
 * @param {string} iv                Base64-encoded IV
 * @returns {Promise<string>} Decrypted plaintext
 */
export async function decryptMessage(sharedKey, encryptedPayload, iv) {
    const ciphertext = base64ToArrayBuffer(encryptedPayload);
    const ivBuffer = base64ToArrayBuffer(iv);

    const plaintext = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: ivBuffer },
        sharedKey,
        ciphertext,
    );

    return new TextDecoder().decode(plaintext);
}

// --- Helpers ---

function arrayBufferToBase64(buffer) {
    return btoa(String.fromCharCode(...new Uint8Array(buffer)));
}

function base64ToArrayBuffer(base64) {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
}
