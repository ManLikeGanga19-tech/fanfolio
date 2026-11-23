<?php

declare(strict_types=1);

namespace App\Modules\Creators\Database\Factories;

use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Models\Creator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Obviously fake creators for tests and local seeds.
 *
 * @extends Factory<Creator>
 */
final class CreatorFactory extends Factory
{
    protected $model = Creator::class;

    public function definition(): array
    {
        $suffix = Str::lower(Str::random(6));

        return [
            'user_id' => (string) Str::ulid(),
            'handle' => 'test.creator.'.$suffix,
            'display_name' => 'Test Creator '.$suffix,
            'category' => 'music',
            'is_adult' => false,
        ];
    }

    public function approved(): self
    {
        return $this->state(fn (): array => [])->afterMaking(function (Creator $creator): void {
            $creator->forceFill([
                'status' => CreatorStatus::Active,
                'kyc_status' => KycStatus::Approved,
                'approved_at' => now(),
            ]);
        });
    }

    public function withStatus(CreatorStatus $status, KycStatus $kyc): self
    {
        return $this->afterMaking(function (Creator $creator) use ($status, $kyc): void {
            $creator->forceFill(['status' => $status, 'kyc_status' => $kyc]);
        });
    }
}
