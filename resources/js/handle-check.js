// "Available" next to the creator handle while typing (Profile basics). The server
// checks again on save; this is only a hint. Bundled by Vite (strict CSP).

export const initHandleChecks = () => {
    document.querySelectorAll('input[data-handle-check]').forEach((input) => {
        const status = document.getElementById(`${input.id}-status`);
        if (!status) {
            return;
        }

        const hint = status.textContent;
        let timer = null;
        let controller = null;

        const show = (message, tone) => {
            status.textContent = message;
            status.classList.remove('text-ink-muted', 'text-success', 'text-danger');
            status.classList.add(tone);
        };

        input.addEventListener('input', () => {
            clearTimeout(timer);
            controller?.abort();

            const handle = input.value.trim();
            if (handle === '') {
                show(hint, 'text-ink-muted');

                return;
            }

            timer = setTimeout(async () => {
                controller = new AbortController();
                try {
                    const url = `${input.dataset.handleCheck}?handle=${encodeURIComponent(handle)}`;
                    const response = await fetch(url, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        signal: controller.signal,
                    });
                    if (!response.ok) {
                        return;
                    }
                    const json = await response.json();
                    show(json.message, json.available ? 'text-success' : 'text-danger');
                } catch {
                    // Aborted or offline: keep the last message.
                }
            }, 400);
        });
    });
};
