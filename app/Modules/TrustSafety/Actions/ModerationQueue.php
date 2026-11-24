<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Actions;

use App\Modules\TrustSafety\Contracts\ModerationStats;
use App\Modules\TrustSafety\Models\ModerationCase;
use Carbon\CarbonImmutable;

final readonly class ModerationQueue implements ModerationStats
{
    public function pending(): int
    {
        return ModerationCase::query()->pending()->count();
    }

    public function urgent(): int
    {
        return ModerationCase::query()->pending()->where('urgent', true)->count();
    }

    public function oldestPendingSince(): ?CarbonImmutable
    {
        $oldest = ModerationCase::query()->pending()->min('created_at');

        return is_string($oldest) ? CarbonImmutable::parse($oldest, 'UTC') : null;
    }
}
