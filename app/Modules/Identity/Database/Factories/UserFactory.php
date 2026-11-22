<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds obviously fake accounts only (example.test addresses).
 *
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    private static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => 'user-'.Str::lower(Str::random(10)).'@example.test',
            'username' => 'fan_'.Str::lower(Str::random(10)),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password-for-tests-only'),
            'remember_token' => Str::random(10),
        ];
    }

    public function withRole(Role $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function withMfa(): static
    {
        return $this->state(fn (): array => ['mfa_enabled' => true]);
    }

    public function withStatus(AccountStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
