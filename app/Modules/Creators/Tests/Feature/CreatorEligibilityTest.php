<?php

declare(strict_types=1);

use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Models\Creator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// M2 "Done when": an unverified creator cannot publish or earn.

it('lets only an approved creator publish and earn', function (): void {
    $creator = Creator::factory()->approved()->create();
    $eligibility = app(CreatorEligibility::class);

    expect($eligibility->canPublish($creator->user_id))->toBeTrue()
        ->and($eligibility->canEarn($creator->user_id))->toBeTrue();
});

it('refuses everyone else', function (CreatorStatus $status, KycStatus $kyc): void {
    $creator = Creator::factory()->withStatus($status, $kyc)->create();
    $eligibility = app(CreatorEligibility::class);

    expect($eligibility->canPublish($creator->user_id))->toBeFalse()
        ->and($eligibility->canEarn($creator->user_id))->toBeFalse();
})->with([
    'onboarding' => [CreatorStatus::Onboarding, KycStatus::NotStarted],
    'with the provider' => [CreatorStatus::Onboarding, KycStatus::Pending],
    'submitted, in review' => [CreatorStatus::Submitted, KycStatus::Review],
    'action needed' => [CreatorStatus::Submitted, KycStatus::ActionNeeded],
    'rejected' => [CreatorStatus::Submitted, KycStatus::Rejected],
    'active but KYC not approved' => [CreatorStatus::Active, KycStatus::Review],
    'suspended' => [CreatorStatus::Suspended, KycStatus::Approved],
]);

it('refuses an account that never applied', function (): void {
    $eligibility = app(CreatorEligibility::class);
    $stranger = (string) Str::ulid();

    expect($eligibility->canPublish($stranger))->toBeFalse()
        ->and($eligibility->canEarn($stranger))->toBeFalse();
});

it('stops at once when a creator is suspended', function (): void {
    $creator = Creator::factory()->approved()->create();
    $eligibility = app(CreatorEligibility::class);
    expect($eligibility->canPublish($creator->user_id))->toBeTrue();

    $creator->forceFill(['status' => CreatorStatus::Suspended, 'suspended_at' => now()])->save();

    expect($eligibility->canPublish($creator->user_id))->toBeFalse()
        ->and($eligibility->canEarn($creator->user_id))->toBeFalse();
});

it('keeps no image, selfie or document number columns on creators (rule 10)', function (): void {
    $columns = Schema::getColumnListing('creators');

    foreach ($columns as $column) {
        expect($column)->not->toMatch('/image|photo|selfie|document|scan|dob|birth/');
    }
});
