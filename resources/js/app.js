import './bootstrap';
import { initPasskeys } from './passkeys';

initPasskeys();

// Let Vite fingerprint the images Blade uses through Vite::asset().
import.meta.glob(['../images/**']);
