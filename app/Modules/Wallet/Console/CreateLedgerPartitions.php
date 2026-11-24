<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily: makes sure ledger entries have a partition for this month and the next ones
 * (spec: partition ledger_entries by month from day one). Entries outside them land in
 * the default partition, so a posting never fails for want of a partition.
 */
final class CreateLedgerPartitions extends Command
{
    protected $signature = 'wallet:ledger-partitions {--months=3 : months ahead to create}';

    protected $description = 'Create monthly partitions for ledger entries ahead of time';

    public function handle(): int
    {
        $month = CarbonImmutable::now('UTC')->startOfMonth();
        $ahead = max(0, (int) $this->option('months'));

        for ($i = 0; $i <= $ahead; $i++) {
            $from = $month->addMonths($i);
            $name = 'ledger_entries_'.$from->format('Y_m');

            DB::statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF ledger_entries FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                $from->format('Y-m-d'),
                $from->addMonth()->format('Y-m-d'),
            ));
        }

        $this->info('Partitions ready to '.$month->addMonths($ahead)->format('M Y').'.');

        return self::SUCCESS;
    }
}
