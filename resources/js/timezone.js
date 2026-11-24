// Decision D-15: times are shown in the signed-in user's own time zone. The page carries
// the saved zone in <meta name="ff-timezone">; when the browser's zone differs, send it.
export function syncTimezone() {
    const meta = document.querySelector('meta[name="ff-timezone"]');
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    if (!meta || !token || !meta.dataset.url) {
        return;
    }

    let zone;
    try {
        zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    } catch {
        return;
    }

    if (!zone || zone === meta.getAttribute('content')) {
        return;
    }

    fetch(meta.dataset.url, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ timezone: zone }),
    })
        .then((response) => {
            if (response.ok) {
                meta.setAttribute('content', zone);
            }
        })
        .catch(() => {});
}
