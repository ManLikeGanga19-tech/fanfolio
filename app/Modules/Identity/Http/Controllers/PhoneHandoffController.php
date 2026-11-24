<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\PhoneHandoffs;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * Decision D-14: the phone side of the ID and face scan. The phone is usually not
 * signed in; the link itself is the (single use, 10 minute) proof.
 */
final class PhoneHandoffController
{
    public function open(string $token, PhoneHandoffs $handoffs): RedirectResponse|Response
    {
        $url = $handoffs->open($token);

        if ($url === null) {
            return response()->view('identity::handoff.expired', [], 410);
        }

        // The provider's hosted capture page (or the development stand-in).
        return redirect()->away($url);
    }

    /** Where the provider sends the phone when the capture is finished. */
    public function done(Request $request): View|RedirectResponse
    {
        // The provider adds its own ref after we signed the URL.
        abort_unless(URL::hasValidSignature($request, ignoreQuery: ['ref']), 403);

        $next = $request->string('next')->toString();
        $ref = $request->string('ref')->toString();

        // Same browser, same account (for example "continue on this computer" in development):
        // carry on where the flow expects.
        if ($request->user()?->getAuthIdentifier() === $request->string('user')->toString() && str_starts_with($next, url('/'))) {
            return redirect()->to($next.(str_contains($next, '?') ? '&' : '?').http_build_query(['ref' => $ref]));
        }

        return view('identity::handoff.done');
    }
}
