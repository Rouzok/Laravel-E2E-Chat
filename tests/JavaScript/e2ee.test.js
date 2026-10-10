import { expect, test } from 'bun:test';
import * as E2EE from '../../resources/js/e2ee.js';

test('a password and recovery phrase restore the same non-exportable chat identity', async () => {
    const phrase = await E2EE.generateRecoveryPhrase();
    expect(E2EE.isValidRecoveryPhrase(phrase)).toBe(true);
    expect(phrase.split(' ')).toHaveLength(24);

    const generated = await E2EE.generatePortableKeyPair();
    expect(generated.keyPair.privateKey.extractable).toBe(false);
    const passwordBackup = await E2EE.encryptKeyBackup(generated, 'current account password');
    const recoveryBackup = await E2EE.encryptKeyBackup(generated, phrase, true);

    const passwordRestored = await E2EE.restoreKeyBackup(passwordBackup, 'current account password');
    const phraseRestored = await E2EE.restoreKeyBackup(recoveryBackup.envelope, phrase, true);

    expect(passwordRestored.keyPair.privateKey.extractable).toBe(false);
    expect(phraseRestored.keyPair.privateKey.extractable).toBe(false);
    expect(passwordRestored.publicKeyJwk.x).toBe(generated.publicKeyJwk.x);
    expect(passwordRestored.publicKeyJwk.y).toBe(generated.publicKeyJwk.y);
    expect(phraseRestored.publicKeyJwk.x).toBe(generated.publicKeyJwk.x);
    expect(phraseRestored.publicKeyJwk.y).toBe(generated.publicKeyJwk.y);
    expect(phraseRestored.verifier).toBe(recoveryBackup.verifier);
});

test('wrong passwords and recovery phrases cannot decrypt the backup', async () => {
    const phrase = await E2EE.generateRecoveryPhrase();
    const generated = await E2EE.generatePortableKeyPair();
    const passwordBackup = await E2EE.encryptKeyBackup(generated, 'correct password');
    const recoveryBackup = await E2EE.encryptKeyBackup(generated, phrase, true);

    await expect(E2EE.restoreKeyBackup(passwordBackup, 'wrong password')).rejects.toThrow();
    await expect(E2EE.restoreKeyBackup(recoveryBackup.envelope, `${phrase} wrong`, true)).rejects.toThrow();
});

test('a restored identity decrypts messages created before restoration', async () => {
    const sender = await E2EE.generatePortableKeyPair();
    const recipient = await E2EE.generatePortableKeyPair();
    const recipientBackup = await E2EE.encryptKeyBackup(recipient, 'account password');
    const senderPublicKey = await E2EE.importPublicKey(sender.publicKeyJwk);
    const recipientPublicKey = await E2EE.importPublicKey(recipient.publicKeyJwk);
    const senderSharedKey = await E2EE.deriveSharedKey(sender.keyPair.privateKey, recipientPublicKey);
    const encrypted = await E2EE.encryptMessage(senderSharedKey, 'portable message');
    const restoredRecipient = await E2EE.restoreKeyBackup(recipientBackup, 'account password');
    const restoredSharedKey = await E2EE.deriveSharedKey(restoredRecipient.keyPair.privateKey, senderPublicKey);

    expect(await E2EE.decryptMessage(restoredSharedKey, encrypted.encryptedPayload, encrypted.iv)).toBe('portable message');
});
