<?php

declare(strict_types=1);

// Brief rule 1 / spec: money moves only through Ledger::post. Inside the Wallet module, only
// the ledger writes its tables; the integrity check and the partition job read or extend them.
// (Other modules naming these tables is already caught by the module-boundary architecture test.)

it('lets only the ledger touch the ledger tables', function (): void {
    $allowed = [
        'Actions/LedgerService.php',
        'Actions/LedgerIntegrity.php',
        'Console/CreateLedgerPartitions.php',
        'Providers/WalletServiceProvider.php',
    ];
    $root = app_path('Modules/Wallet/');
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = substr((string) $file, strlen($root));
        if (! str_ends_with($relative, '.php') || str_starts_with($relative, 'Tests/') || str_starts_with($relative, 'Database/Migrations/') || in_array($relative, $allowed, true)) {
            continue;
        }

        if (preg_match('/[\'"]ledger_(entries|balances|transactions|accounts)/', (string) file_get_contents((string) $file)) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});
