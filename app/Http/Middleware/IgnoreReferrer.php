<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-02 sends Referrer-Policy: strict-origin, so browsers send only "https://site/"
 * as the Referer, even on same-site form posts. Laravel's redirect()->back() prefers
 * the Referer, which would send every failed form to the landing page. Dropping it
 * makes "back" use the last page stored in the session instead. Nothing in the app
 * reads the Referer.
 */
final class IgnoreReferrer
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->remove('referer');

        return $next($request);
    }
}
