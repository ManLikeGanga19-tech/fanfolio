<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Creators\Contracts\ApprovalQueue;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Models\Creator;
use Carbon\CarbonImmutable;

final readonly class CreatorApprovalQueue implements ApprovalQueue
{
    public function waiting(): int
    {
        return Creator::query()->where('status', CreatorStatus::Submitted)->count();
    }

    public function oldestWaitingSince(): ?CarbonImmutable
    {
        $oldest = Creator::query()->where('status', CreatorStatus::Submitted)->min('submitted_at');

        return is_string($oldest) ? CarbonImmutable::parse($oldest, 'UTC') : null;
    }

    public function approvedBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Creator::query()
            ->where('approved_at', '>=', $from->utc())
            ->where('approved_at', '<', $to->utc())
            ->count();
    }
}
