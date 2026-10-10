/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
import * as E2EE from './e2ee';

window.E2EE = E2EE;

const chatLoginPasswordKey = 'chat-key-login-password';

if (document.querySelector('form[data-chat-key-login]')) {
    try {
        sessionStorage.removeItem(chatLoginPasswordKey);
    } catch {}
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('form[data-chat-key-login]')) return;

    const password = form.elements.namedItem('password')?.value;
    if (password) {
        try {
            sessionStorage.setItem(chatLoginPasswordKey, password);
        } catch {}
    }
}, true);

window.consumeChatLoginPassword = () => {
    try {
        const password = sessionStorage.getItem(chatLoginPasswordKey);
        sessionStorage.removeItem(chatLoginPasswordKey);
        return password;
    } catch {
        return null;
    }
};

window.updatePasswordBackup = async function (wire, form) {
    const error = form.querySelector('[data-password-backup-error]');
    const inputs = Array.from(form.querySelectorAll('input'));
    const currentPassword = inputs.find((input) => input.getAttribute('wire:model') === 'current_password')?.value;
    const newPassword = inputs.find((input) => input.getAttribute('wire:model') === 'password')?.value;
    error.textContent = '';

    try {
        const response = await fetch('/user/key-backup', { headers: { 'Accept': 'application/json' } });
        if (!response.ok) throw new Error('Could not load the encrypted chat key backup.');
        const backupInfo = await response.json();
        let passwordBackup = null;

        if (backupInfo.has_backup) {
            const restored = await window.E2EE.restoreKeyBackup(backupInfo.password_key_backup, currentPassword);
            passwordBackup = await window.E2EE.encryptKeyBackup(restored, newPassword);
        }

        await wire.updatePassword(passwordBackup);
    } catch (exception) {
        error.textContent = 'The current password could not unlock the chat key backup. Check it and try again.';
    }
};
