<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Contracts;

use Carbon\CarbonImmutable;

/** Moderation figures for the admin Overview and navigation (plan M8a, use case 1). */
interface ModerationStats
{
    /** Cases open or escalated. */
    public function pending(): int;

    /** Pending cases that may involve a minor (1-hour target). */
    public function urgent(): int;

    /** When the oldest pending case was opened, or null when none are pending. */
    public function oldestPendingSince(): ?CarbonImmutable;
}
