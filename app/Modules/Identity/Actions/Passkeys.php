<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidation;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidator;
use Laragear\WebAuthn\Exceptions\AssertionException;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;
use Laragear\WebAuthn\Models\WebAuthnCredential;

/**
 * RegisterPasskey and LoginWithPasskey. Ceremonies always require user verification
 * (PIN or biometric), so a passkey on its own is multi-factor (SEC-04).
 */
final readonly class Passkeys
{
    public function __construct(
        private AssertionValidator $assertions,
        private SyncMfaStatus $sync,
        private AuditLog $audit,
    ) {}

    /**
     * RegisterPasskey: stores the attested credential (already validated by the request).
     */
    public function register(User $user, AttestedRequest $request, ?string $alias): string
    {
        return DB::transaction(function () use ($user, $request, $alias): string {
            $id = $request->save(['alias' => $alias]);

            $this->audit->record('identity.passkey_added', $user->id, 'user', $user->id, ['credential' => substr($id, 0, 16)]);
            $this->sync->handle($user);

            return $id;
        });
    }

    /**
     * LoginWithPasskey: validates a signed assertion and returns its owner. With $expected
     * set (step-up), the passkey must belong to that user.
     */
    public function assert(Request $request, ?User $expected = null): User
    {
        try {
            $validation = $this->assertions
                ->send(new AssertionValidation(AssertionValidation::fromRequest($request)->json, $expected))
                ->thenReturn();
        } catch (AssertionException) {
            throw new IdentityException('That passkey could not be checked. Try again.', 'passkey');
        }

        $credential = $validation->credential;
        $user = $credential?->authenticatable;

        if (! $credential instanceof WebAuthnCredential || ! $user instanceof User) {
            throw new IdentityException('That passkey could not be checked. Try again.', 'passkey');
        }

        if (! $user->canSignIn()) {
            $this->audit->record('identity.login_refused', $user->id, 'user', $user->id, ['status' => $user->status->value]);

            throw new IdentityException('This account cannot sign in. Contact support.', 'passkey');
        }

        $credential->forceFill(['last_used_at' => now()])->save();

        return $user;
    }

    public function remove(User $user, string $credentialId): void
    {
        DB::transaction(function () use ($user, $credentialId): void {
            $credential = $user->webAuthnCredentials()->whereKey($credentialId)->first();

            if (! $credential instanceof WebAuthnCredential) {
                throw new IdentityException('Passkey not found.', 'passkey');
            }

            $others = $user->webAuthnCredentials()->whereKeyNot($credentialId)->whereNull('disabled_at')->exists();
            if ($user->role->requiresMfa() && ! $others && ! $user->hasConfirmedTotp()) {
                throw new IdentityException('Your account needs a second sign-in method. Set up an authenticator app before removing this passkey.', 'passkey');
            }

            $credential->delete();
            $this->audit->record('identity.passkey_removed', $user->id, 'user', $user->id, ['credential' => substr($credentialId, 0, 16)]);
            $this->sync->handle($user);
        });
    }
}
