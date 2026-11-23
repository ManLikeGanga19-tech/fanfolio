<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Auth\PasswordBroker as Broker;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * "Forgot password?". Tokens are hashed, single use and expire (config/auth.php).
 * Also the recovery path for passkey-only accounts: they can add a password this way.
 * A reset never skips MFA: the next login still asks for the second factor.
 */
final readonly class ResetPassword
{
    public function __construct(
        private PasswordPolicy $passwords,
        private Devices $devices,
        private AuditLog $audit,
    ) {}

    /**
     * Sends the link if the email has an account. The caller shows the same reply
     * either way, so this never reveals which emails are registered.
     */
    public function sendLink(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! $user->status->canSignIn()) {
            return;
        }

        $status = $this->broker()->sendResetLink(['email' => $email]);

        if ($status === Broker::RESET_LINK_SENT) {
            $this->audit->record('identity.password_reset_requested', null, 'user', $user->id);
        }
    }

    public function reset(string $email, string $token, string $password): User
    {
        $this->passwords->assertAcceptable($password);

        $reset = null;
        $status = $this->broker()->reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (User $user, string $password) use (&$reset): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $reset = $user;
            },
        );

        if ($status !== Broker::PASSWORD_RESET || ! $reset instanceof User) {
            throw new IdentityException('This reset link has expired or was already used. Ask for a new one.', 'email');
        }

        // Anyone holding an old session is signed out.
        $signedOut = $this->devices->revokeAll($reset);

        $this->audit->record('identity.password_reset', $reset->id, 'user', $reset->id, ['devices_signed_out' => $signedOut]);

        return $reset;
    }

    private function broker(): Broker
    {
        return Password::broker();
    }
}
