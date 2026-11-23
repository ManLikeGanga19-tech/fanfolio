<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Contracts\IdentityVerifier;
use App\Modules\Identity\Gateways\Fake\FakeIdentityVerifier;
use App\Support\DevMode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Decision D-14, development only: a stand-in for the verification partner's hosted
 * capture page. It accepts any ID photo and any selfie. The images stay on the device
 * (the file and camera fields have no name, so nothing is uploaded, rule 10); only the
 * outcome the developer picks is sent.
 */
final class FakeKycCaptureController
{
    public function show(string $ref, IdentityVerifier $verifier): View
    {
        $fake = $this->fake($verifier);
        abort_unless($fake->hasCapture($ref), 404);

        return view('identity::fake.kyc-capture', ['ref' => $ref, 'outcomes' => FakeIdentityVerifier::OUTCOMES]);
    }

    public function finish(Request $request, string $ref, IdentityVerifier $verifier): RedirectResponse
    {
        $return = $this->fake($verifier)->finishCapture($ref, $request->string('outcome', 'pass')->toString());
        abort_if($return === null, 404);

        return redirect()->to($return);
    }

    private function fake(IdentityVerifier $verifier): FakeIdentityVerifier
    {
        abort_unless(DevMode::enabled() && $verifier instanceof FakeIdentityVerifier, 404);

        return $verifier;
    }
}
