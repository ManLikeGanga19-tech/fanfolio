<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-01 and SEC-02: HSTS, a strict nonce-based CSP, no framing, strict referrer.
 *
 * The nonce is created per request and handed to Vite, so every script and style tag
 * the app emits carries it. No inline script runs without the nonce.
 */
final class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce, $this->isAdmin($request)));
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()');

        if ($request->isSecure() || app()->isProduction()) {
            $maxAge = config()->integer('fanfolio.security.hsts_max_age');
            $headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains; preload");
        }

        return $response;
    }

    /**
     * Decision D-07: the admin and Studio panels (Filament on Livewire and Alpine) need
     * runtime evaluation and inline styles. Only /admin, /studio and the Livewire
     * endpoints they call get them; every fan page keeps the strict policy.
     */
    private function isAdmin(Request $request): bool
    {
        $admin = config()->string('fanfolio.admin.path');
        $studio = config()->string('fanfolio.studio.path');

        return $request->is($admin, $admin.'/*', $studio, $studio.'/*', 'livewire/*');
    }

    private function contentSecurityPolicy(string $nonce, bool $admin): string
    {
        $directives = [
            'default-src' => "'self'",
            'script-src' => $admin ? "'self' 'nonce-{$nonce}' 'unsafe-eval'" : "'self' 'nonce-{$nonce}'",
            'style-src' => $admin ? "'self' 'unsafe-inline'" : "'self' 'nonce-{$nonce}'",
            'img-src' => "'self' data: blob:",
            'font-src' => "'self'",
            'connect-src' => "'self'",
            'media-src' => "'self' blob:",
            'object-src' => "'none'",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'frame-ancestors' => "'none'",
        ];

        $policy = [];
        foreach ($directives as $name => $value) {
            $policy[] = "{$name} {$value}";
        }

        if (app()->isProduction()) {
            $policy[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $policy);
    }
}
