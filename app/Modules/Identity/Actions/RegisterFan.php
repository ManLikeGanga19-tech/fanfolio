<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Events\UserRegistered;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sign up step 1. Input shape and uniqueness are validated by the request; this
 * enforces the password rules (SEC-03) and creates the fan account. The phone number
 * is optional and stays unverified (D-12): it is stored encrypted, without the keyed
 * hash, so an unverified number can never block its real owner from adding it.
 */
final readonly class RegisterFan
{
    public function __construct(
        private PasswordPolicy $passwords,
        private AuditLog $audit,
    ) {}

    public function handle(string $email, string $username, string $password, ?string $phoneE164 = null): User
    {
        $this->passwords->assertAcceptable($password);

        return DB::transaction(function () use ($email, $username, $password, $phoneE164): User {
            $user = User::query()->create([
                'email' => $email,
                'username' => $username,
                'password' => $password,
            ]);

            if ($phoneE164 !== null) {
                $user->forceFill(['phone_e164' => $phoneE164])->save();
            }

            $this->audit->record('identity.registered', $user->id, 'user', $user->id, ['method' => 'password']);
            UserRegistered::dispatch($user->id);

            return $user;
        });
    }
}
