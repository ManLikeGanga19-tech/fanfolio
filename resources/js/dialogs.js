// Native <dialog> sheets (for example the "Report" sheet): a button with
// data-dialog-open="id" opens one as a modal, [data-dialog-close] inside closes it, and a
// click on the backdrop closes it too. The browser handles focus, Escape and the inert page.

export const initDialogs = () => {
    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const opener = target?.closest('[data-dialog-open]');

        if (opener) {
            const dialog = document.getElementById(opener.getAttribute('data-dialog-open') ?? '');
            if (dialog instanceof HTMLDialogElement && !dialog.open) {
                event.preventDefault();
                dialog.showModal();
            }
            return;
        }

        const closer = target?.closest('[data-dialog-close]');
        if (closer) {
            closer.closest('dialog')?.close();
            return;
        }

        // A click on the backdrop lands on the dialog element itself.
        if (target instanceof HTMLDialogElement && target.open) {
            target.close();
        }
    });
};
