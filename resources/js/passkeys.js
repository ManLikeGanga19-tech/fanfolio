// Passkeys (WebAuthn) for the fan PWA. Bundled by Vite so it runs under the strict CSP:
// no inline script, no eval. Any button with data-passkey="create" or data-passkey="get"
// runs a ceremony against its data-options-url and data-submit-url.

const fromBase64Url = (value) => {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4);

    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
};

const toBase64 = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer)));

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const post = async (url, body = {}) => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    const json = await response.json().catch(() => ({}));

    if (!response.ok) {
        // The server may send the person elsewhere: to confirm it is them, or to the device limit page.
        const next = json.confirm ?? json.redirect;
        if (next) {
            window.location.assign(next);
        }
        // Validation errors (422) list each field's message; show them all.
        const fieldErrors = json.errors ? Object.values(json.errors).flat().join(' ') : '';
        throw new Error(fieldErrors || json.message || 'Something went wrong. Try again.');
    }

    return json;
};

const decodeOptions = (options) => {
    const publicKey = { ...options, challenge: fromBase64Url(options.challenge) };

    if (publicKey.user) {
        publicKey.user = { ...publicKey.user, id: fromBase64Url(publicKey.user.id) };
    }

    for (const key of ['excludeCredentials', 'allowCredentials']) {
        if (Array.isArray(publicKey[key])) {
            publicKey[key] = publicKey[key].map((item) => ({ ...item, id: fromBase64Url(item.id) }));
        }
    }

    return publicKey;
};

const encodeCredential = (credential) => {
    const response = {};

    for (const key of ['clientDataJSON', 'attestationObject', 'authenticatorData', 'signature', 'userHandle']) {
        if (credential.response[key]) {
            response[key] = toBase64(credential.response[key]);
        }
    }

    return {
        id: credential.id,
        rawId: toBase64(credential.rawId),
        type: credential.type,
        authenticatorAttachment: credential.authenticatorAttachment ?? null,
        clientExtensionResults: credential.getClientExtensionResults?.() ?? {},
        response,
    };
};

// With data-form="<form id>", the options request carries that form's fields (never a
// password or the CSRF field, which travels in the header).
const formFields = (formId) => {
    const form = formId ? document.getElementById(formId) : null;
    if (!form) {
        return {};
    }

    const fields = {};
    for (const [name, value] of new FormData(form)) {
        if (name !== '_token' && !name.startsWith('password')) {
            fields[name] = value;
        }
    }

    return fields;
};

const run = async (button) => {
    const status = document.getElementById(button.dataset.statusId ?? 'passkey-status');
    const say = (message) => {
        if (status) {
            status.textContent = message;
        }
    };

    button.disabled = true;
    say('');

    try {
        const options = decodeOptions(await post(button.dataset.optionsUrl, formFields(button.dataset.form)));
        const credential = button.dataset.passkey === 'create'
            ? await navigator.credentials.create({ publicKey: options })
            : await navigator.credentials.get({ publicKey: options });

        const result = await post(button.dataset.submitUrl, encodeCredential(credential));
        window.location.assign(result.redirect ?? window.location.href);
    } catch (error) {
        // NotAllowedError means the person cancelled or the prompt timed out.
        say(error?.name === 'NotAllowedError' ? 'Passkey cancelled. Try again when you are ready.' : (error?.message ?? 'Try again.'));
        button.disabled = false;
    }
};

export const initPasskeys = () => {
    const supported = typeof window.PublicKeyCredential === 'function';

    document.querySelectorAll('[data-passkey]').forEach((button) => {
        if (!supported) {
            button.hidden = true;

            return;
        }

        button.addEventListener('click', () => run(button));
    });
};
