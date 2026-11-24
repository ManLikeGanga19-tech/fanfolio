import './bootstrap';
import { initHandleChecks } from './handle-check';
import { initKycCapture } from './kyc-capture';
import { initPasskeys } from './passkeys';
import { initPasswordToggles } from './password-toggle';

initPasskeys();
initHandleChecks();
initPasswordToggles();
initKycCapture();

// Let Vite fingerprint the images Blade uses through Vite::asset().
import.meta.glob(['../images/**/*.{webp,jpg,jpeg,png,avif}']);
