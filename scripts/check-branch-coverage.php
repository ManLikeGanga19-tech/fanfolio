<?php

declare(strict_types=1);

/*
 * CI gate (brief "Definition of done": 100% line and branch coverage on the Wallet and
 * Subscriptions money paths; M3 "Done when": 100% coverage on the Wallet module).
 * Reads a Cobertura report written with PHPUnit's --path-coverage (Xdebug):
 *
 * - every class: 100% of lines;
 * - classes under the money-path prefixes (default "Actions/"): 100% of branches too.
 *
 * Xdebug counts some compiler paths no test can take (a loop that always runs at least
 * once, enum constant fetches), so branches are gated where money moves, not on data
 * objects and fake drivers. In the money paths, import global functions (`use function
 * trim;`): PHP 8.4 otherwise compiles calls such as trim() or in_array() inside a
 * namespace with a check for a namespaced function of that name, a branch no test can take.
 *
 * Usage: php scripts/check-branch-coverage.php storage/wallet-coverage.xml [prefix ...]
 */

$file = $argv[1] ?? '';
$prefixes = array_slice($argv, 2) ?: ['Actions/'];
$xml = is_file($file) ? simplexml_load_file($file) : false;

if ($xml === false) {
    fwrite(STDERR, "Coverage report not found: {$file}\n");
    exit(2);
}

$short = [];
foreach ($xml->xpath('//class') ?: [] as $class) {
    $name = (string) $class['filename'];
    $line = (string) $class['line-rate'];
    $branch = (string) $class['branch-rate'];
    $moneyPath = array_filter($prefixes, static fn (string $prefix): bool => str_starts_with($name, $prefix)) !== [];

    if ($line !== '1' || ($moneyPath && $branch !== '1')) {
        $short[] = sprintf('%s: lines %s, branches %s', (string) $class['filename'], $line, $branch);
    }
}

printf(
    "Wallet coverage: lines %s/%s, branches %s/%s\n",
    (string) $xml['lines-covered'],
    (string) $xml['lines-valid'],
    (string) $xml['branches-covered'],
    (string) $xml['branches-valid'],
);

if ($short !== []) {
    fwrite(STDERR, "Not fully covered:\n  ".implode("\n  ", $short)."\n");
    exit(1);
}

echo '100% of lines covered; 100% of branches on the money paths ('.implode(', ', $prefixes).").\n";
