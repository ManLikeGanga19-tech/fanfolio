// Show or hide what is typed in a password field (the eye button in <x-ui.input>).
// Bundled by Vite, so it runs under the strict CSP. Without JavaScript the button
// stays hidden and the field works as a normal password field.

export const initPasswordToggles = () => {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) {
            return;
        }

        const showLabel = button.getAttribute('aria-label');
        const hideLabel = showLabel.replace(/^Show /, 'Hide ');

        button.hidden = false;
        button.addEventListener('click', () => {
            const reveal = input.type === 'password';

            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(reveal));
            button.setAttribute('aria-label', reveal ? hideLabel : showLabel);
            button.querySelector('[data-icon="show"]')?.classList.toggle('hidden', reveal);
            button.querySelector('[data-icon="hide"]')?.classList.toggle('hidden', !reveal);
        });

        // Never submit, or leave on screen, a revealed password.
        input.form?.addEventListener('submit', () => {
            input.type = 'password';
        });
    });
};
