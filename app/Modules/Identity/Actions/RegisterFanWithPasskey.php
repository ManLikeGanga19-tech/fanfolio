<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Events\UserRegistered;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laragear\WebAuthn\Attestation\Creator\AttestationCreation;
use Laragear\WebAuthn\Attestation\Creator\AttestationCreator;
use Laragear\WebAuthn\Attestation\Validator\AttestationValidation;
use Laragear\WebAuthn\Attestation\Validator\AttestationValidator;
use Laragear\WebAuthn\Enums\ResidentKey;
use Laragear\WebAuthn\Enums\UserVerification;
use Laragear\WebAuthn\Exceptions\AttestationException;
use Laragear\WebAuthn\JsonTransport;
use Laragear\WebAuthn\Models\WebAuthnCredential;

/**
 * Sign up step 1, "Continue with a passkey". The account is created only once the
 * passkey is made and checked, so a cancelled prompt leaves nothing behind. The
 * passkey requires user verification, so the account starts with MFA on (SEC-04).
 */
final readonly class RegisterFanWithPasskey
{
    public const string SESSION_KEY = 'identity.passkey_signup';

    private const int TTL_SECONDS = 300;

    public function __construct(
        private AttestationCreator $creator,
        private AttestationValidator $validator,
        private SyncMfaStatus $sync,
        private AuditLog $audit,
    ) {}

    /**
     * Holds the sign-up details in the session and returns the passkey creation options.
     */
    public function options(Session $session, string $email, string $username): JsonTransport
    {
        $pending = [
            'id' => (string) Str::ulid(),
            'email' => $email,
            'username' => $username,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ];

        $creation = new AttestationCreation(
            $this->draft($pending),
            residentKey: ResidentKey::Required,
            userVerification: UserVerification::Required,
        );

        /** @var JsonTransport $json */
        $json = $this->creator->send($creation)->then(static fn (AttestationCreation $c): JsonTransport => $c->json);

        $session->put(self::SESSION_KEY, $pending);

        return $json;
    }

    /**
     * Checks the new passkey and creates the account with it.
     *
     * @param  array<string, mixed>  $attestation
     */
    public function complete(Session $session, array $attestation, ?string $alias): User
    {
        $pending = $this->pending($session);
        $user = $this->draft($pending);

        try {
            /** @var WebAuthnCredential $credential */
            $credential = $this->validator
                ->send(new AttestationValidation($user, new JsonTransport($attestation)))
                ->then(static fn (AttestationValidation $v): ?WebAuthnCredential => $v->credential);
        } catch (AttestationException) {
            throw new IdentityException('That passkey could not be set up. Try again.', 'passkey');
        }

        try {
            $user = DB::transaction(function () use ($user, $credential, $alias): User {
                $user->save();

                $credential->forceFill(['alias' => $alias])->save();

                $this->audit->record('identity.registered', $user->id, 'user', $user->id, ['method' => 'passkey']);
                $this->audit->record('identity.passkey_added', $user->id, 'user', $user->id, ['credential' => substr($credential->id, 0, 16)]);
                $this->sync->handle($user);

                UserRegistered::dispatch($user->id);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Someone took the email or username between the two steps.
            throw new IdentityException('This email or username is no longer free. Start again.', 'email');
        } finally {
            $session->forget(self::SESSION_KEY);
        }

        return $user;
    }

    /**
     * @return array{id: string, email: string, username: string, expires_at: int}
     */
    private function pending(Session $session): array
    {
        $pending = $session->get(self::SESSION_KEY);

        if (! is_array($pending)
            || ! is_string($pending['id'] ?? null)
            || ! is_string($pending['email'] ?? null)
            || ! is_string($pending['username'] ?? null)
            || ! is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] < now()->getTimestamp()) {
            $session->forget(self::SESSION_KEY);

            throw new IdentityException('Your sign-up timed out. Start again.', 'passkey');
        }

        return [
            'id' => $pending['id'],
            'email' => $pending['email'],
            'username' => $pending['username'],
            'expires_at' => $pending['expires_at'],
        ];
    }

    /**
     * An unsaved account with its id fixed in advance, so the passkey is tied to it.
     *
     * @param  array{id: string, email: string, username: string, expires_at: int}  $pending
     */
    private function draft(array $pending): User
    {
        $user = new User(['email' => $pending['email'], 'username' => $pending['username']]);
        $user->id = $pending['id'];

        return $user;
    }
}
