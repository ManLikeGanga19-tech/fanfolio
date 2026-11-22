<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use App\Support\Modules\ModuleServiceProvider;
use RuntimeException;

/**
 * The modules under app/Modules and the tables each one owns, read from each
 * module provider's TABLES constant.
 */
final readonly class ModuleMap
{
    /**
     * @param  array<string, list<string>>  $tables  module => owned tables
     */
    public function __construct(public array $tables) {}

    public static function discover(string $modulesPath): self
    {
        $tables = [];

        foreach (glob($modulesPath.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $module = basename($dir);
            $provider = "App\\Modules\\{$module}\\Providers\\{$module}ServiceProvider";

            if (! class_exists($provider) || ! is_subclass_of($provider, ModuleServiceProvider::class)) {
                throw new RuntimeException("Module {$module} has no provider {$provider} extending ModuleServiceProvider.");
            }

            $tables[$module] = array_values($provider::TABLES);
        }

        ksort($tables);

        return new self($tables);
    }

    /**
     * @return list<string>
     */
    public function modules(): array
    {
        return array_keys($this->tables);
    }

    /**
     * @return list<string>
     */
    public function tablesOf(string $module): array
    {
        return $this->tables[$module] ?? [];
    }

    /**
     * @return array<string, string> table => owning module (last owner wins; see duplicateOwners())
     */
    public function owners(): array
    {
        $owners = [];
        foreach ($this->tables as $module => $tables) {
            foreach ($tables as $table) {
                $owners[$table] = $module;
            }
        }

        return $owners;
    }

    /**
     * @return array<string, list<string>> table => modules, for tables claimed more than once
     */
    public function duplicateOwners(): array
    {
        $claims = [];
        foreach ($this->tables as $module => $tables) {
            foreach ($tables as $table) {
                $claims[$table][] = $module;
            }
        }

        return array_filter($claims, static fn (array $modules): bool => count($modules) > 1);
    }

    public function isModule(string $name): bool
    {
        return array_key_exists($name, $this->tables);
    }
}
