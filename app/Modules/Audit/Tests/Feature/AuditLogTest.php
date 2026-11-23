<?php

declare(strict_types=1);

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Audit\Models\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Rule 12 and SEC-12: audit records are never edited or deleted.

it('records an entry with the request address', function (): void {
    app(AuditLog::class)->record('test.action', null, 'thing', '01TESTSUBJECT', ['outcome' => 'ok']);

    $entry = AuditEntry::query()->sole();

    expect($entry->action)->toBe('test.action')
        ->and($entry->context)->toBe(['outcome' => 'ok'])
        ->and($entry->ip)->toBe('127.0.0.1');
});

it('rejects UPDATE at the database level', function (): void {
    app(AuditLog::class)->record('test.action', null);

    expect(fn () => DB::transaction(fn () => DB::table('audit_log')->update(['action' => 'changed'])))
        ->toThrow(QueryException::class, 'audit_log is append-only');
});

it('rejects DELETE at the database level', function (): void {
    app(AuditLog::class)->record('test.action', null);

    expect(fn () => DB::transaction(fn () => DB::table('audit_log')->delete()))
        ->toThrow(QueryException::class, 'audit_log is append-only');

    expect(AuditEntry::query()->count())->toBe(1);
});

it('rejects edits through the model', function (): void {
    app(AuditLog::class)->record('test.action', null);
    $entry = AuditEntry::query()->sole();

    expect(fn () => $entry->update(['action' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});
