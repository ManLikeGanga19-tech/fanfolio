<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Tests\Support\RecordingAuditLog;

// SEC-15 and rule 13: the /admin panel is for staff with MFA, from allowed addresses.
// M1 "Done when": admins cannot sign in without MFA.

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
});

function adminWithMfa(Role $role = Role::Admin): User
{
    $admin = User::factory()->withRole($role)->create();
    enableTotp($admin);

    return $admin;
}

it('sends guests to log in', function (): void {
    $this->get('/admin')->assertRedirect(route('login'));
});

it('refuses fans and creators', function (Role $role): void {
    $user = User::factory()->withRole($role)->create();
    if ($role->requiresMfa()) {
        enableTotp($user);
    } else {
        actAs($user);
    }

    $this->get('/admin')->assertForbidden();
    expect($this->audit->has('admin.access_denied', $user->id))->toBeTrue();
})->with([Role::Fan, Role::Creator]);

it('keeps admins without MFA out of the panel', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    actAs($admin);

    $this->get('/admin')->assertRedirect(route('account.security'));
    $this->assertAuthenticatedAs($admin);
});

it('lets an admin with MFA in, and records the session', function (Role $role): void {
    $staff = adminWithMfa($role);

    $this->get('/admin')->assertOk();
    $this->get('/admin')->assertOk();

    expect($this->audit->of('admin.panel_opened'))->toHaveCount(1)
        ->and($this->audit->has('admin.panel_opened', $staff->id))->toBeTrue();
})->with([Role::Admin, Role::Moderator]);

it('refuses addresses outside the allow-list', function (): void {
    config(['fanfolio.admin.ip_allowlist' => ['10.0.0.0/8']]);
    $admin = adminWithMfa();

    $this->get('/admin')->assertForbidden();
    expect($this->audit->of('admin.access_denied')[0]['context'])->toBe(['reason' => 'ip_not_allowed']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->get('/admin')->assertOk();
    expect($this->audit->has('admin.panel_opened', $admin->id))->toBeTrue();
});

it('is closed outside local and testing when no allow-list is set', function (): void {
    adminWithMfa();
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/admin')->assertForbidden();
});

it('stays closed while SSO is required but no provider is set up', function (): void {
    config(['fanfolio.admin.sso_required' => true]);
    adminWithMfa();

    $this->get('/admin')->assertForbidden();
    expect($this->audit->of('admin.access_denied')[0]['context'])->toBe(['reason' => 'sso_required']);
});

it('relaxes the CSP on /admin only (decision D-07)', function (): void {
    adminWithMfa();

    $admin = (string) $this->get('/admin')->headers->get('Content-Security-Policy');
    $fan = (string) $this->get('/account')->headers->get('Content-Security-Policy');

    expect($admin)->toContain("'unsafe-eval'")->toContain("frame-ancestors 'none'")
        ->and($fan)->not->toContain('unsafe-eval')->not->toContain('unsafe-inline');
});
