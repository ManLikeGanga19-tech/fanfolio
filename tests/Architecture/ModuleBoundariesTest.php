<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Tests\Architecture\Support\Files;
use Tests\Architecture\Support\ModuleMap;
use Tests\Architecture\Support\SourceScanner;

/*
| Architecture gates from CLAUDE.md. Each one fails the build when broken.
*/

const EXPECTED_MODULES = [
    'Audit', 'Collaborations', 'Content', 'Creators', 'Identity', 'Media',
    'Messaging', 'Notifications', 'Subscriptions', 'TrustSafety', 'Wallet',
];

/** Framework tables in database/migrations that belong to no module. */
const FRAMEWORK_TABLES = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

it('has exactly the eleven modules from the brief', function (): void {
    expect(ModuleMap::discover(app_path('Modules'))->modules())->toBe(EXPECTED_MODULES);
});

it('registers every module provider and gives every module a README', function (): void {
    $providers = require base_path('bootstrap/providers.php');

    foreach (EXPECTED_MODULES as $module) {
        expect($providers)->toContain("App\\Modules\\{$module}\\Providers\\{$module}ServiceProvider")
            ->and(app_path("Modules/{$module}/README.md"))->toBeFile();
    }
});

it('assigns every table to exactly one module', function (): void {
    $map = ModuleMap::discover(app_path('Modules'));

    expect($map->duplicateOwners())->toBe([]);

    foreach ($map->modules() as $module) {
        expect($map->tablesOf($module))->not->toBeEmpty("{$module} declares no tables.");
    }

    expect(array_intersect(array_keys($map->owners()), FRAMEWORK_TABLES))->toBe([]);
});

it('keeps modules out of each other\'s code and tables', function (): void {
    $map = ModuleMap::discover(app_path('Modules'));
    $scanner = new SourceScanner;
    $violations = [];

    foreach ($map->modules() as $module) {
        foreach (Files::php(app_path("Modules/{$module}")) as $file) {
            $isMigration = str_contains($file, "/Modules/{$module}/Database/Migrations/");
            array_push($violations, ...$scanner->moduleViolations((string) file_get_contents($file), $module, $map, Files::relative($file), $isMigration));
        }
    }

    expect($violations)->toBe([], Files::report($violations));
});

it('keeps framework migrations free of module tables', function (): void {
    $map = ModuleMap::discover(app_path('Modules'));
    $owned = array_keys($map->owners());
    $violations = [];

    foreach (Files::php(database_path('migrations')) as $file) {
        $code = (string) file_get_contents($file);
        foreach ($owned as $table) {
            if (preg_match("/Schema::\\w+\\(\\s*['\"]{$table}['\"]/", $code) === 1) {
                $violations[] = Files::relative($file)." creates or alters module table \"{$table}\"; move it into the owning module.";
            }
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('never uses floats in application code', function (): void {
    $scanner = new SourceScanner;
    $violations = [];

    foreach (Files::php(app_path()) as $file) {
        array_push($violations, ...$scanner->floatViolations((string) file_get_contents($file), Files::relative($file)));
    }

    expect($violations)->toBe([], Files::report($violations));
});

it('reads env() only inside config', function (): void {
    $scanner = new SourceScanner;
    $violations = [];

    foreach (['app', 'bootstrap', 'database', 'routes', 'resources', 'tests'] as $dir) {
        foreach (Files::php(base_path($dir)) as $file) {
            if (str_contains($file, '/bootstrap/cache/')) {
                continue;
            }
            array_push($violations, ...$scanner->envViolations((string) file_get_contents($file), Files::relative($file)));
        }
    }

    expect($violations)->toBe([], Files::report($violations));
});

it('dispatches every domain event after commit', function (): void {
    $missing = [];

    foreach (EXPECTED_MODULES as $module) {
        foreach (Files::php(app_path("Modules/{$module}/Events")) as $file) {
            $class = "App\\Modules\\{$module}\\Events\\".basename($file, '.php');
            if (class_exists($class) && ! is_subclass_of($class, ShouldDispatchAfterCommit::class)) {
                $missing[] = $class;
            }
        }
    }

    expect($missing)->toBe([], 'Events must implement ShouldDispatchAfterCommit: '.implode(', ', $missing));
});

arch('the shared kernel never depends on modules')
    ->expect('App\Support')
    ->not->toUse('App\Modules');

arch('application code declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging helpers are left in code')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('value objects in contracts are immutable')
    ->expect('App\Modules\*\Contracts\Data')
    ->classes()
    ->toBeReadonly();
