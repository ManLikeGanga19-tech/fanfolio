<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Console;

use App\Modules\Wallet\Actions\LedgerIntegrity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Nightly (spec "Integrity checks"). Any mismatch is logged as critical; paging comes
 * with observability in M9. The last result is kept for the admin Overview.
 */
final class VerifyLedger extends Command
{
    public const string RESULT_KEY = 'wallet:ledger-check';

    protected $signature = 'wallet:verify-ledger';

    protected $description = 'Check that every ledger transaction balances and every cached balance matches its entries';

    public function handle(LedgerIntegrity $integrity): int
    {
        $report = $integrity->check();
        $ok = $report['unbalanced'] === [] && $report['mismatched'] === [];

        Cache::forever(self::RESULT_KEY, ['ok' => $ok, 'checked_at' => now()->toIso8601String()] + $report);

        if (! $ok) {
            Log::critical('Ledger integrity check failed', $report);
            $this->error('Ledger mismatch: '.count($report['unbalanced']).' unbalanced transaction(s), '.count($report['mismatched']).' balance(s) out of step.');

            return self::FAILURE;
        }

        $this->info('Ledger balanced.');

        return self::SUCCESS;
    }
}
