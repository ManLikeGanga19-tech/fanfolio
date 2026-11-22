<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleMap;
use Tests\Architecture\Support\SourceScanner;
use Tests\Architecture\Support\Violation;

/*
| Proves each architecture rule catches a violation, using small bad fixtures.
| If one of these fails, a rule has silently stopped protecting the codebase.
*/

function fixtureMap(): ModuleMap
{
    return new ModuleMap([
        'Wallet' => ['ledger_entries', 'payouts'],
        'Content' => ['posts', 'post_media'],
        'Identity' => ['users'],
    ]);
}

/**
 * @param  list<Violation>  $violations
 * @return list<string>
 */
function archRules(array $violations): array
{
    return array_values(array_unique(array_map(static fn (Violation $v): string => $v->rule, $violations)));
}

it('allows the public surface of another module', function (): void {
    $code = <<<'PHP'
    <?php
    namespace App\Modules\Wallet\Actions;
    use App\Modules\Content\Contracts\PostCatalog;
    use App\Modules\Content\Events\PostPublished;
    final class Spend { public function __construct(private PostCatalog $posts) {} }
    PHP;

    expect((new SourceScanner)->moduleViolations($code, 'Wallet', fixtureMap(), 'Spend.php', false))->toBe([]);
});

it('rejects using another module\'s models', function (): void {
    $code = <<<'PHP'
    <?php
    namespace App\Modules\Wallet\Actions;
    use App\Modules\Content\Models\Post;
    final class Spend { public function run(): void { Post::query()->first(); } }
    PHP;

    expect(archRules((new SourceScanner)->moduleViolations($code, 'Wallet', fixtureMap(), 'Spend.php', false)))->toBe(['boundary']);
});

it('rejects reading another module\'s table through the query builder', function (string $snippet): void {
    $code = "<?php\nnamespace App\\Modules\\Wallet\\Actions;\nuse Illuminate\\Support\\Facades\\DB;\nfinal class Spend { public function run(): mixed { return {$snippet}; } }";

    expect(archRules((new SourceScanner)->moduleViolations($code, 'Wallet', fixtureMap(), 'Spend.php', false)))->toContain('foreign-table');
})->with([
    'DB::table' => "DB::table('posts')->get()",
    'raw select' => "DB::select('select * from posts where id = ?', [1])",
    'join' => "DB::table('payouts')->join('users', 'users.id', '=', 'payouts.user_id')->get()",
    'qualified column' => "DB::table('payouts')->where('posts.id', 1)->get()",
    'validation rule' => "['post_id' => 'exists:posts,id']",
]);

it('rejects a model pointing at another module\'s table', function (): void {
    $code = <<<'PHP'
    <?php
    namespace App\Modules\Wallet\Models;
    use Illuminate\Database\Eloquent\Model;
    final class SneakyPost extends Model { protected $table = 'posts'; }
    PHP;

    expect(archRules((new SourceScanner)->moduleViolations($code, 'Wallet', fixtureMap(), 'SneakyPost.php', false)))->toBe(['foreign-table']);
});

it('rejects migrations that touch tables the module does not own', function (): void {
    $code = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Schema;
    return new class { public function up(): void {
        Schema::table('posts', function ($t) { $t->string('x'); });
        Schema::create('orphans', function ($t) { $t->ulid('id')->primary(); });
    } };
    PHP;

    $violations = (new SourceScanner)->moduleViolations($code, 'Wallet', fixtureMap(), 'migration.php', true);

    expect(archRules($violations))->toContain('migration-table')
        ->and(array_filter($violations, static fn (Violation $v): bool => $v->rule === 'migration-table'))->toHaveCount(2);
});

it('rejects auto-increment keys and implicit foreign keys in module migrations', function (): void {
    $code = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Schema;
    return new class { public function up(): void {
        Schema::create('payouts', function ($t) { $t->id(); $t->foreignUlid('creator_id')->constrained(); });
    } };
    PHP;

    expect(archRules((new SourceScanner)->moduleViolations($code, 'Wallet', fixtureMap(), 'migration.php', true)))
        ->toContain('auto-increment')
        ->toContain('implicit-fk');
});

it('accepts a clean module migration', function (): void {
    $code = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Schema;
    return new class { public function up(): void {
        Schema::create('payouts', function ($t) {
            $t->ulid('id')->primary();
            $t->bigInteger('amount_minor');
            $t->foreignUlid('entry_id')->constrained('ledger_entries');
        });
    } };
    PHP;

    expect((new SourceScanner)->moduleViolations($code, 'Wallet', fixtureMap(), 'migration.php', true))->toBe([]);
});

it('rejects floats for money', function (string $snippet): void {
    $code = "<?php\nfinal class Fee { {$snippet} }";

    expect(archRules((new SourceScanner)->floatViolations($code, 'Fee.php')))->toBe(['float']);
})->with([
    'float param' => 'public function apply(float $rate): void {}',
    'float return' => 'public function rate(): float { return 1; }',
    'nullable float property' => 'public ?float $rate = null;',
    'union float' => 'public function x(int|float $v): void {}',
    'float literal' => 'public function fee(): int { return (int) (100 * 0.2); }',
    'float cast' => 'public function x(string $v): int { return (int) (float) $v; }',
    'division' => 'public function x(int $a): int { return (int) ($a / 5); }',
    'floatval' => 'public function x(string $v): int { return (int) floatval($v); }',
    'decimal cast' => 'protected function casts(): array { return ["price" => "decimal:2"]; }',
    'decimal column' => 'public function up($t): void { $t->decimal("amount", 10, 2); }',
]);

it('accepts integer money code', function (): void {
    $code = '<?php final class Fee { public function share(int $minor, int $bps): int { return intdiv($minor * $bps, 10000); } }';

    expect((new SourceScanner)->floatViolations($code, 'Fee.php'))->toBe([]);
});

it('rejects env() outside config', function (): void {
    $code = "<?php\nfinal class Gateway { public function key(): string { return (string) env('GATEWAY_KEY'); } }";

    expect(archRules((new SourceScanner)->envViolations($code, 'Gateway.php')))->toBe(['env']);
});
