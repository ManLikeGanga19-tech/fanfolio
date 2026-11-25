<?php

declare(strict_types=1);

use App\Modules\Audit\Contracts\AuditHistory;
use App\Modules\Audit\Contracts\AuditLog;
use Carbon\CarbonImmutable;

// Dispute evidence (M3 PR 6, canvas AdminDisputes): earlier sign-ins from the top-up's IP address.

it('counts a person\'s earlier actions from one IP address', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'UTC'));
    $log = app(AuditLog::class);
    $me = '01jtestactor00000000000000';

    $log->record('identity.login', $me);
    $log->record('identity.login', $me);
    $log->record('identity.login', '01jtestsomeoneelse00000000');
    $log->record('identity.logout', $me);
    $this->travel(1)->hour();
    $log->record('identity.login', $me);

    $history = app(AuditHistory::class);

    expect($history->countFromIp('identity.login', $me, '127.0.0.1', CarbonImmutable::parse('2026-11-25 10:30', 'UTC')))->toBe(2)
        ->and($history->countFromIp('identity.login', $me, '127.0.0.1', CarbonImmutable::now()->addMinute()))->toBe(3)
        ->and($history->countFromIp('identity.login', $me, '203.0.113.1', CarbonImmutable::now()->addMinute()))->toBe(0);
});
