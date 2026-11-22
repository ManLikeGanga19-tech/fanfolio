<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Static checks behind the architecture tests. Works on source strings, so the rules
 * can be proven against bad fixture code as well as run over the real codebase.
 */
final readonly class SourceScanner
{
    /** Namespaces of another module that a module is allowed to use. */
    public const array PUBLIC_SURFACE = ['Contracts', 'Events'];

    private const array SCHEMA_METHODS = ['create', 'table', 'drop', 'dropIfExists', 'rename', 'hasTable', 'dropColumns', 'hasColumn', 'hasColumns'];

    private const array AUTO_INCREMENT_METHODS = ['id', 'increments', 'bigIncrements', 'mediumIncrements', 'smallIncrements', 'tinyIncrements', 'integerIncrements', 'foreignId', 'foreignIdFor'];

    private const array FLOAT_COLUMN_METHODS = ['float', 'double', 'decimal', 'unsignedDecimal', 'unsignedFloat', 'unsignedDouble'];

    private const array FLOAT_TYPES = ['float', 'double'];

    private Parser $parser;

    private NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->finder = new NodeFinder;
    }

    /**
     * Module boundary and table ownership rules for one file inside app/Modules/<module>.
     *
     * @return list<Violation>
     */
    public function moduleViolations(string $code, string $module, ModuleMap $map, string $file, bool $isMigration): array
    {
        $ast = $this->parse($code);
        $violations = [];
        $owners = $map->owners();
        $ownTables = $map->tablesOf($module);

        // 1. Cross-module references only through Contracts and Events.
        $namespaceNames = [];
        foreach ($this->finder->findInstanceOf($ast, Stmt\Namespace_::class) as $ns) {
            if ($ns->name !== null) {
                $namespaceNames[spl_object_id($ns->name)] = true;
            }
        }

        foreach ($this->finder->findInstanceOf($ast, Name::class) as $name) {
            if (isset($namespaceNames[spl_object_id($name)])) {
                continue;
            }
            $this->checkReference($name->toString(), $module, $map, $file, $name->getStartLine(), $violations);
        }

        // 2. No string literal naming another module's table.
        foreach ($this->finder->findInstanceOf($ast, Scalar\String_::class) as $string) {
            $this->checkReference($string->value, $module, $map, $file, $string->getStartLine(), $violations);

            foreach ($this->tablesNamedIn($string->value) as $table) {
                $owner = $owners[$table] ?? null;
                if ($owner !== null && $owner !== $module) {
                    $violations[] = new Violation('foreign-table', $file, $string->getStartLine(), "{$module} names table \"{$table}\" owned by {$owner}.");
                }
            }
        }

        if (! $isMigration) {
            return $violations;
        }

        // 3. Migrations touch only tables this module owns.
        foreach ($this->finder->findInstanceOf($ast, Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Name || ! $call->name instanceof Node\Identifier) {
                continue;
            }
            if (! str_ends_with($call->class->toString(), 'Schema') || ! in_array($call->name->toString(), self::SCHEMA_METHODS, true)) {
                continue;
            }
            $first = $call->getArgs()[0]->value ?? null;
            if (! $first instanceof Scalar\String_) {
                $violations[] = new Violation('migration-table', $file, $call->getStartLine(), 'Schema calls in module migrations must name the table as a string literal.');

                continue;
            }
            if (! in_array($first->value, $ownTables, true)) {
                $violations[] = new Violation('migration-table', $file, $call->getStartLine(), "{$module} migration touches table \"{$first->value}\", which it does not own.");
            }
        }

        // 4. ULID keys only, and no implicit foreign keys.
        foreach ($this->finder->findInstanceOf($ast, Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }
            $method = $call->name->toString();
            if (in_array($method, self::AUTO_INCREMENT_METHODS, true)) {
                $violations[] = new Violation('auto-increment', $file, $call->getStartLine(), "Use ULIDs: {$method}() is not allowed in module migrations.");
            }
            if ($method === 'constrained' && $call->getArgs() === []) {
                $violations[] = new Violation('implicit-fk', $file, $call->getStartLine(), 'constrained() must name its table explicitly.');
            }
        }

        return $violations;
    }

    /**
     * No floats for money: no float types, float literals, float casts, division, floatval(),
     * float/decimal Eloquent casts or float/decimal columns.
     *
     * @return list<Violation>
     */
    public function floatViolations(string $code, string $file): array
    {
        $ast = $this->parse($code);
        $violations = [];

        foreach ($this->finder->find($ast, static fn (Node $n): bool => $n instanceof Node\Identifier || $n instanceof Name) as $node) {
            /** @var Node\Identifier|Name $node */
            if (in_array(strtolower($node->toString()), self::FLOAT_TYPES, true) && $this->isTypePosition($node, $ast)) {
                $violations[] = new Violation('float', $file, $node->getStartLine(), 'float type declaration.');
            }
        }

        foreach ($this->finder->findInstanceOf($ast, Scalar\Float_::class) as $node) {
            $violations[] = new Violation('float', $file, $node->getStartLine(), 'float literal; use integer minor units or basis points.');
        }

        foreach ($this->finder->findInstanceOf($ast, Expr\Cast\Double::class) as $node) {
            $violations[] = new Violation('float', $file, $node->getStartLine(), '(float) cast.');
        }

        foreach ($this->finder->findInstanceOf($ast, Expr\BinaryOp\Div::class) as $node) {
            $violations[] = new Violation('float', $file, $node->getStartLine(), '"/" can produce a float; use intdiv().');
        }

        foreach ($this->finder->findInstanceOf($ast, Expr\AssignOp\Div::class) as $node) {
            $violations[] = new Violation('float', $file, $node->getStartLine(), '"/=" can produce a float; use intdiv().');
        }

        foreach ($this->finder->findInstanceOf($ast, Expr\FuncCall::class) as $call) {
            if ($call->name instanceof Name && in_array(strtolower($call->name->getLast()), ['floatval', 'doubleval'], true)) {
                $violations[] = new Violation('float', $file, $call->getStartLine(), $call->name->getLast().'() call.');
            }
        }

        foreach ($this->finder->findInstanceOf($ast, Scalar\String_::class) as $string) {
            if (preg_match('/^(float|double|real|decimal(:\d+)?)$/i', $string->value) === 1) {
                $violations[] = new Violation('float', $file, $string->getStartLine(), "\"{$string->value}\" cast.");
            }
        }

        foreach ($this->finder->findInstanceOf($ast, Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && in_array($call->name->toString(), self::FLOAT_COLUMN_METHODS, true)) {
                $violations[] = new Violation('float', $file, $call->getStartLine(), $call->name->toString().'() column.');
            }
        }

        return $violations;
    }

    /**
     * env() may only be called inside config/.
     *
     * @return list<Violation>
     */
    public function envViolations(string $code, string $file): array
    {
        $violations = [];

        foreach ($this->finder->findInstanceOf($this->parse($code), Expr\FuncCall::class) as $call) {
            if ($call->name instanceof Name && strtolower($call->name->getLast()) === 'env') {
                $violations[] = new Violation('env', $file, $call->getStartLine(), 'env() outside config/; read config() instead.');
            }
        }

        return $violations;
    }

    /**
     * @param  list<Violation>  $violations
     */
    private function checkReference(string $reference, string $module, ModuleMap $map, string $file, int $line, array &$violations): void
    {
        if (preg_match('/^\\\\?App\\\\Modules\\\\(\w+)(?:\\\\(\w+))?/', $reference, $m) !== 1) {
            return;
        }

        $target = $m[1];
        $area = $m[2] ?? '';

        if ($target === $module || ! $map->isModule($target)) {
            return;
        }

        if (! in_array($area, self::PUBLIC_SURFACE, true)) {
            $violations[] = new Violation('boundary', $file, $line, "{$module} uses {$reference}; only {$target}\\Contracts and {$target}\\Events are public.");
        }
    }

    /**
     * Table names a string literal refers to: the whole string, "table.column", SQL clauses,
     * and validation rules such as exists:table,column.
     *
     * @return list<string>
     */
    private function tablesNamedIn(string $value): array
    {
        $tables = [];
        $trimmed = trim($value, " \t\n\r\0\x0B\"`");

        if (preg_match('/^\w+$/', $trimmed) === 1) {
            $tables[] = $trimmed;
        }

        if (preg_match('/^(\w+)\.\w+$/', $trimmed, $m) === 1) {
            $tables[] = $m[1];
        }

        if (preg_match_all('/\b(?:from|join|into|update|table)\s+["`]?(\w+)/i', $value, $matches) > 0) {
            array_push($tables, ...$matches[1]);
        }

        if (preg_match_all('/\b(?:exists|unique):(\w+)/i', $value, $matches) > 0) {
            array_push($tables, ...$matches[1]);
        }

        return array_values(array_unique($tables));
    }

    /**
     * @param  array<Node>  $ast
     */
    private function isTypePosition(Node $node, array $ast): bool
    {
        $id = spl_object_id($node);

        $typed = $this->finder->find($ast, static fn (Node $n): bool => $n instanceof Node\Param
            || $n instanceof Node\FunctionLike
            || $n instanceof Stmt\Property
            || $n instanceof Node\NullableType
            || $n instanceof Node\UnionType
            || $n instanceof Node\IntersectionType
            || $n instanceof Stmt\ClassConst);

        foreach ($typed as $owner) {
            $type = match (true) {
                $owner instanceof Node\Param, $owner instanceof Stmt\Property, $owner instanceof Stmt\ClassConst => $owner->type,
                $owner instanceof Node\FunctionLike => $owner->getReturnType(),
                $owner instanceof Node\NullableType => $owner->type,
                default => null,
            };

            if ($type === $node) {
                return true;
            }

            if ($owner instanceof Node\UnionType || $owner instanceof Node\IntersectionType) {
                foreach ($owner->types as $member) {
                    if (spl_object_id($member) === $id) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array<Node>
     */
    private function parse(string $code): array
    {
        $ast = $this->parser->parse($code) ?? [];
        $traverser = new NodeTraverser(new NameResolver);

        return $traverser->traverse($ast);
    }
}
