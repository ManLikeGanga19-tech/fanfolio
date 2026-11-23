<?php

declare(strict_types=1);

/*
| FanFolio business configuration. Every value here comes from the brief, the backend
| spec or the screens. Open decisions use the exact placeholders from CLAUDE.md.
| All money is integer minor units: USD cents, and 1 credit = 1 USD cent.
*/

return [

    'brand' => [
        'name' => 'FanFolio',
        'domain' => env('FANFOLIO_DOMAIN', '[domain]'),
        'privacy_email' => env('FANFOLIO_PRIVACY_EMAIL', 'privacy@[domain]'),
        'statement_descriptor' => 'FF*DIGITAL',
    ],

    'money' => [
        'currency' => 'USD',
        'credits_per_usd' => 100,
        'platform_fee_bps' => 2000,
        'earnings_hold_days' => 7,
        'payout_min_minor' => 2000,
        'payout_manual_review_above_minor' => 100000,
        'idempotency_ttl_hours' => 24,
        'wallet_lock_ttl_seconds' => 2,
    ],

    // Top-up packs (decision D-04): the paid amount is in USD cents; the bonus is in basis points of the credits bought.
    'topup' => [
        'packs' => [
            ['amount_minor' => 500, 'bonus_bps' => 0],
            ['amount_minor' => 1000, 'bonus_bps' => 0],
            ['amount_minor' => 2500, 'bonus_bps' => 400],
            ['amount_minor' => 5000, 'bonus_bps' => 1000],
        ],
        'methods' => ['card', 'crypto'],
    ],

    'subscriptions' => [
        'period_days' => [30, 90, 180],
        'past_due_grace_days' => 3,
        'renewal_batch_size' => 500,
    ],

    'identity' => [
        'max_active_devices' => 3,
        'password_min_length' => 12,
        // Phone one-time codes: screen "Verify phone (OTP)".
        'phone_code' => [
            'digits' => 6,
            'ttl_minutes' => 10,
            'max_attempts' => 5,
            'resend_seconds' => 60,
        ],
        'username_change_days' => 30,
    ],

    // SEC-01: two years, the minimum for the HSTS preload list.
    'security' => [
        'hsts_max_age' => 63072000,
    ],

    'media' => [
        'signed_url_ttl_seconds' => 60,
        'upload_url_ttl_minutes' => 15,
        'max_video_bytes' => 4 * 1024 * 1024 * 1024,
        'software_drm_max_height' => 480,
    ],

    'collaborations' => [
        'invite_ttl_days' => 7,
    ],

    'legal' => [
        'credit_refund_policy' => '[CREDIT REFUND POLICY]',
        'legal_text' => '[LEGAL TEXT]',
    ],

    // Gateway drivers. Only "fake" exists until a provider is chosen (see CLAUDE.md open decisions).
    // A fake driver refuses to run in production.
    'drivers' => [
        'topup' => env('FANFOLIO_TOPUP_DRIVER', 'fake'),
        'payout' => [
            'bank' => env('FANFOLIO_PAYOUT_BANK_DRIVER', 'fake'),
            'usdt' => env('FANFOLIO_PAYOUT_USDT_DRIVER', 'fake'),
            'paxum' => env('FANFOLIO_PAYOUT_PAXUM_DRIVER', 'fake'),
        ],
        'fx' => env('FANFOLIO_FX_DRIVER', 'fake'),
        'kyc' => env('FANFOLIO_KYC_DRIVER', 'fake'),
        'sms' => env('FANFOLIO_SMS_DRIVER', 'fake'),
        // "hibp" is the Pwned Passwords range API (k-anonymity, no account or key).
        'breached_passwords' => env('FANFOLIO_BREACHED_PASSWORDS_DRIVER', 'fake'),
        'drm' => env('FANFOLIO_DRM_DRIVER', 'fake'),
        'malware' => env('FANFOLIO_MALWARE_DRIVER', 'fake'),
        'abuse_hash' => env('FANFOLIO_ABUSE_HASH_DRIVER', 'fake'),
        'classifier' => env('FANFOLIO_CLASSIFIER_DRIVER', 'fake'),
        'leak_crawler' => env('FANFOLIO_LEAK_CRAWLER_DRIVER', 'fake'),
    ],

    // Settings for the fake drivers only (local and testing).
    'fake' => [
        'kyc_outcome' => env('FANFOLIO_FAKE_KYC_OUTCOME', 'pass'),
        'webhook_secret' => env('FANFOLIO_FAKE_WEBHOOK_SECRET'),
        'fx_rates' => [
            'USD:KES' => 130_000_000,
        ],
    ],

];
