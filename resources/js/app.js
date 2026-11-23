import './bootstrap';
import { initPasskeys } from './passkeys';
import { initPasswordToggles } from './password-toggle';

initPasskeys();
initPasswordToggles();

// Let Vite fingerprint the images Blade uses through Vite::asset().
import.meta.glob(['../images/**/*.{webp,jpg,jpeg,png,avif}']);
