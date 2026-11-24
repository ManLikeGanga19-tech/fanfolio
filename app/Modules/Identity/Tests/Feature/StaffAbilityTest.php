<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

// SEC-15 least privilege: the role matrix in docs/plans/M8a.md, one check per cell.
// Moderators work queues; admins also ban, lift bans, see the audit log, manage staff
// and settings (decision D-18).

const ADMIN_ONLY = [
    StaffAbility::ViewSystemHealth,
    StaffAbility::SuspendCreator,
    StaffAbility::ReinstateCreator,
    StaffAbility::PauseCreatorPayouts,
    StaffAbility::BanUser,
    StaffAbility::LiftRestriction,
    StaffAbility::ResetSecondFactor,
    StaffAbility::ViewAuditLog,
    StaffAbility::ExportAuditLog,
    StaffAbility::ManageStaff,
    StaffAbility::ViewSettings,
    StaffAbility::ProposeSettings,
];

/** @return array<string, array{StaffAbility, Role, bool}> */
function staffMatrix(): array
{
    $cells = [];
    foreach (StaffAbility::cases() as $ability) {
        foreach (Role::cases() as $role) {
            $allowed = match ($role) {
                Role::Fan, Role::Creator => false,
                Role::Moderator => ! in_array($ability, ADMIN_ONLY, true),
                Role::Admin => true,
            };
            $cells["{$role->value} · {$ability->name}"] = [$ability, $role, $allowed];
        }
    }

    return $cells;
}

it('follows the role matrix', function (StaffAbility $ability, Role $role, bool $allowed): void {
    $user = User::factory()->withRole($role)->withMfa()->create();

    expect(Gate::forUser($user)->allows($ability->value))->toBe($allowed)
        ->and(app(Accounts::class)->can($user->id, $ability))->toBe($allowed);
})->with(staffMatrix());

it('gives admins nothing without MFA', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();

    foreach (StaffAbility::cases() as $ability) {
        expect(Gate::forUser($admin)->allows($ability->value))->toBeFalse();
    }
});

it('gives suspended or banned staff nothing', function (AccountStatus $status): void {
    $admin = User::factory()->withRole(Role::Admin)->withMfa()->withStatus($status)->create();

    expect(Gate::forUser($admin)->allows(StaffAbility::ViewOverview->value))->toBeFalse()
        ->and(app(Accounts::class)->can($admin->id, StaffAbility::ViewOverview))->toBeFalse();
})->with([AccountStatus::Suspended, AccountStatus::Banned]);

it('answers no for an unknown account', function (): void {
    expect(app(Accounts::class)->can('01JUNKNOWN0000000000000000', StaffAbility::ViewOverview))->toBeFalse();
});
