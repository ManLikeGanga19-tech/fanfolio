// Decision D-14.
//
// 1. Forms that start an ID or face scan carry a hidden "device" field. A touch-first
//    device (a phone) goes straight to the capture; anything else gets a QR code to
//    continue on a phone. Without this script the server assumes a computer.
// 2. The development stand-in capture page: local previews and a live camera. Nothing
//    is uploaded: the inputs have no name and the snapshot stays in a canvas.

const isPhone = () => window.matchMedia('(pointer: coarse)').matches && !window.matchMedia('(hover: hover)').matches;

const initDeviceFields = () => {
    for (const field of document.querySelectorAll('input[type="hidden"][name="device"][data-detect]')) {
        field.value = isPhone() ? 'phone' : 'computer';
    }
};

const initPreviews = (root) => {
    for (const input of root.querySelectorAll('input[type="file"][data-preview-target]')) {
        input.addEventListener('change', () => {
            const image = document.getElementById(input.dataset.previewTarget);
            const file = input.files?.[0];
            if (!image || !file) {
                return;
            }
            const reader = new FileReader();
            reader.addEventListener('load', () => {
                image.src = String(reader.result);
                image.classList.remove('hidden');
            });
            reader.readAsDataURL(file);
        });
    }
};

const initCamera = (root) => {
    const video = root.querySelector('[data-camera]');
    const canvas = root.querySelector('[data-snapshot]');
    const status = root.querySelector('[data-camera-status]');
    const start = root.querySelector('[data-camera-start]');
    const take = root.querySelector('[data-camera-take]');
    if (!video || !canvas || !status || !start || !take) {
        return;
    }

    let stream = null;

    start.addEventListener('click', async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            status.textContent = 'The live camera needs a secure (https) page. Use the camera app below instead.';
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
        } catch {
            status.textContent = 'Camera not available. Use the camera app below instead.';
            return;
        }
        video.srcObject = stream;
        video.classList.remove('hidden');
        canvas.classList.add('hidden');
        take.hidden = false;
        start.hidden = true;
        status.textContent = 'Camera on. Turn your head slowly, then take the selfie.';
    });

    take.addEventListener('click', () => {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d')?.drawImage(video, 0, 0);
        for (const track of stream?.getTracks() ?? []) {
            track.stop();
        }
        stream = null;
        video.classList.add('hidden');
        canvas.classList.remove('hidden');
        take.hidden = true;
        start.hidden = false;
        start.textContent = 'Retake';
        status.textContent = 'Selfie taken. It stays on this device.';
    });
};

export const initKycCapture = () => {
    initDeviceFields();

    const root = document.querySelector('[data-fake-kyc]');
    if (root) {
        initPreviews(root);
        initCamera(root);
    }
};
