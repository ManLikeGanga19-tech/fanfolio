<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Creators\Contracts\Data\PayoutDestination;
use App\Modules\Creators\Contracts\PayoutDestinations;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\PayoutMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final readonly class PayoutDestinationLookup implements PayoutDestinations
{
    public function forUser(string $userId): array
    {
        return array_values($this->methods($userId)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get()
            ->map($this->destination(...))
            ->all());
    }

    public function find(string $userId, string $methodId): ?PayoutDestination
    {
        $method = $this->methods($userId)->whereKey($methodId)->first();

        return $method instanceof PayoutMethod ? $this->destination($method) : null;
    }

    public function instructionFor(string $userId, string $methodId): ?array
    {
        $method = $this->methods($userId)->whereKey($methodId)->first();

        return $method instanceof PayoutMethod ? ['account_name' => $method->account_name, 'details' => $method->details] : null;
    }

    /**
     * @return Builder<PayoutMethod>
     */
    private function methods(string $userId): Builder
    {
        $creatorId = Creator::query()->where('user_id', $userId)->value('id');

        return PayoutMethod::query()->where('creator_id', is_string($creatorId) ? $creatorId : '')->whereNull('revoked_at');
    }

    private function destination(PayoutMethod $method): PayoutDestination
    {
        return new PayoutDestination(
            $method->id,
            $method->rail,
            $method->display_hint,
            $method->name_matches_kyc,
            $method->is_default,
            $method->created_at ?? CarbonImmutable::now(),
        );
    }
}
