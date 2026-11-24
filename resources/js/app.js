import './bootstrap';
import { initAuthCarousels } from './auth-carousel';
import { initHandleChecks } from './handle-check';
import { initKycCapture } from './kyc-capture';
import { initPasskeys } from './passkeys';
import { initPasswordToggles } from './password-toggle';
import { syncTimezone } from './timezone';

syncTimezone();
initPasskeys();
initHandleChecks();
initPasswordToggles();
initKycCapture();
initAuthCarousels();

// Let Vite fingerprint the images Blade uses through Vite::asset().
import.meta.glob(['../images/**/*.{webp,jpg,jpeg,png,avif}']);
