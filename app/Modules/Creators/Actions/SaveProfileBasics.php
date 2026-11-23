<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Support\HandleRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Step 1 of 4, "Set up your page": display name, handle, category, adult flag.
 * Editable while onboarding; once submitted, changes go through support (M8).
 */
final readonly class SaveProfileBasics
{
    public function __construct(private AuditLog $audit) {}

    public function handle(Creator $creator, string $displayName, string $handle, string $category, bool $isAdult): Creator
    {
        if ($creator->status !== CreatorStatus::Onboarding) {
            throw new CreatorException('Your application is already submitted. Contact support to change your page.', 'handle');
        }

        $handle = HandleRules::normalise($handle);

        if (($problem = HandleRules::problem($handle)) !== null) {
            throw new CreatorException($problem, 'handle');
        }

        if (! array_key_exists($category, config()->array('fanfolio.creators.categories'))) {
            throw new CreatorException('Choose a category.', 'category');
        }

        if (! $this->available($handle, $creator)) {
            throw new CreatorException('This handle is taken.', 'handle');
        }

        try {
            DB::transaction(function () use ($creator, $displayName, $handle, $category, $isAdult): void {
                $creator->fill([
                    'display_name' => trim($displayName),
                    'handle' => $handle,
                    'category' => $category,
                    'is_adult' => $isAdult,
                ])->save();

                $this->audit->record('creator.profile_saved', $creator->user_id, 'creator', $creator->id, [
                    'handle' => $handle,
                    'is_adult' => $isAdult,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new CreatorException('This handle is taken.', 'handle');
        }

        return $creator;
    }

    public function available(string $handle, ?Creator $for = null): bool
    {
        $handle = HandleRules::normalise($handle);

        if (HandleRules::problem($handle) !== null) {
            return false;
        }

        return ! Creator::query()
            ->where('handle', $handle)
            ->when($for instanceof Creator, static fn ($q) => $q->whereKeyNot($for?->id))
            ->exists();
    }
}
