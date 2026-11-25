<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Creators\Contracts\CreatorPages;
use App\Modules\Creators\Contracts\Data\ApplicationStage;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Models\Creator;

final readonly class CreatorPageLookup implements CreatorPages
{
    public function userIdForHandle(string $handle): ?string
    {
        $handle = ltrim(trim($handle), '@');

        if ($handle === '') {
            return null;
        }

        // Only pages that are, or were, public. citext: the match ignores case.
        $userId = Creator::query()
            ->where('handle', $handle)
            ->whereIn('status', [CreatorStatus::Active, CreatorStatus::Suspended])
            ->value('user_id');

        return is_string($userId) ? $userId : null;
    }

    public function handleForUser(string $userId): ?string
    {
        $handle = Creator::query()->where('user_id', $userId)->value('handle');

        return is_string($handle) ? $handle : null;
    }

    public function applicationStage(string $userId): ?ApplicationStage
    {
        // value() applies the model's cast: the status comes back as the enum.
        $status = Creator::query()->where('user_id', $userId)->value('status');

        return match ($status) {
            CreatorStatus::Onboarding => ApplicationStage::Started,
            CreatorStatus::Submitted => ApplicationStage::InReview,
            default => null,
        };
    }
}
