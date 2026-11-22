<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUlids;
    use Notifiable;

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
