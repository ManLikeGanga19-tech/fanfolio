<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laragear\WebAuthn\WebAuthnData;

/**
 * @property string $id
 * @property string $email
 * @property string $username
 * @property string $password
 * @property string|null $phone_e164
 * @property string|null $phone_hash
 * @property CarbonImmutable|null $phone_verified_at
 * @property Role $role
 * @property AccountStatus $status
 * @property bool $mfa_enabled
 * @property CarbonImmutable|null $age_verified_at
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable $created_at
 */
final class User extends Authenticatable implements WebAuthnAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUlids;
    use Notifiable;
    use WebAuthnAuthentication;

    /**
     * Role, status, MFA and verification flags are never mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'username',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'phone_e164',
        'phone_hash',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'fan',
        'status' => 'active',
        'mfa_enabled' => false,
    ];

    /**
     * Passkeys show the username, never the email or legal name.
     */
    public function webAuthnData(): WebAuthnData
    {
        return WebAuthnData::make('@'.$this->username, '@'.$this->username);
    }

    /**
     * @return HasOne<TotpSecret, $this>
     */
    public function totp(): HasOne
    {
        return $this->hasOne(TotpSecret::class);
    }

    /**
     * @return HasMany<RecoveryCode, $this>
     */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(RecoveryCode::class);
    }

    public function hasPasskey(): bool
    {
        return $this->webAuthnCredentials()->whereNull('disabled_at')->exists();
    }

    public function hasConfirmedTotp(): bool
    {
        return $this->totp()->whereNotNull('confirmed_at')->exists();
    }

    public function isAgeVerified(): bool
    {
        return $this->age_verified_at !== null;
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'phone_verified_at' => 'immutable_datetime',
            'age_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'password' => 'hashed',
            'phone_e164' => 'encrypted',
            'role' => Role::class,
            'status' => AccountStatus::class,
            'mfa_enabled' => 'boolean',
        ];
    }
}
