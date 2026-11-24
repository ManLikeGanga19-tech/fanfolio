<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\LedgerIntegrity;

/**
 * M3 "Done when": every test ends with the ledger balanced. Wallet tests call this in
 * afterEach; other modules' money tests (M5, M6) call it too.
 */
function expectLedgerBalanced(): void
{
    expect(app(LedgerIntegrity::class)->check())->toBe(['unbalanced' => [], 'mismatched' => []]);
}
