<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;

// SEC-04: MFA is mandatory for creators, moderators and admins.

it('sends staff and creators without MFA to set it up', function (Role $role): void {
    $this->actingAs(User::factory()->withRole($role)->create());

    $this->get('/account')
        ->assertRedirect(route('account.security'))
        ->assertSessionHas('status', 'Your account needs a passkey or an authenticator app. Set one up to continue.');

    $this->get('/account/security')->assertOk();
    $this->get('/account/confirm')->assertOk();
    $this->post('/logout')->assertRedirect(route('landing'));
})->with([Role::Creator, Role::Moderator, Role::Admin]);

it('lets them through once MFA is on', function (): void {
    $creator = User::factory()->withRole(Role::Creator)->create();
    enableTotp($creator);

    $this->get('/account')->assertOk();
});

it('does not require MFA from fans', function (): void {
    $this->actingAs(User::factory()->create())->get('/account')->assertOk();
});

it('answers JSON requests with 403 instead of a redirect', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Creator)->create());

    $this->getJson('/account')->assertForbidden();
});
