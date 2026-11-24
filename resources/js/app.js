import './bootstrap';
import { initAuthCarousels } from './auth-carousel';
import { initDialogs } from './dialogs';
import { initHandleChecks } from './handle-check';
import { initKycCapture } from './kyc-capture';
import { initPasskeys } from './passkeys';
import { initPasswordToggles } from './password-toggle';
import { initReveals } from './reveal';
import { syncTimezone } from './timezone';

syncTimezone();
initReveals();
initPasskeys();
initHandleChecks();
initPasswordToggles();
initKycCapture();
initAuthCarousels();
initDialogs();

// Let Vite fingerprint the images Blade uses through Vite::asset().
import.meta.glob(['../images/**/*.{webp,jpg,jpeg,png,avif}']);
