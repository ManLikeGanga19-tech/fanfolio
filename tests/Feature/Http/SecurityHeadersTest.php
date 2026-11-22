<?php

declare(strict_types=1);

// SEC-01 and SEC-02: header tests that run in CI.

it('sends a strict nonce-based content security policy', function (): void {
    $csp = (string) $this->get('/terms')->assertOk()->headers->get('Content-Security-Policy');

    expect($csp)
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/")
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")
        ->not->toContain('unsafe-inline')
        ->not->toContain('unsafe-eval');
});

it('uses a fresh nonce on every request', function (): void {
    $first = $this->get('/terms')->headers->get('Content-Security-Policy');
    $second = $this->get('/terms')->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});

it('forbids framing, sniffing and leaky referrers', function (): void {
    $this->get('/terms')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
});

it('sends HSTS with preload over HTTPS', function (): void {
    $this->get('https://localhost/terms')
        ->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
});

it('does not send HSTS over plain HTTP outside production', function (): void {
    $this->get('http://localhost/terms')->assertHeaderMissing('Strict-Transport-Security');
});

it('adds the headers to error responses too', function (): void {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy');
});

it('issues session cookies that are secure, HttpOnly and SameSite=Lax', function (): void {
    // Browsers accept Secure cookies on http://localhost, so this holds in local dev too.
    expect(config('session.secure'))->toBeTrue()
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');
});
