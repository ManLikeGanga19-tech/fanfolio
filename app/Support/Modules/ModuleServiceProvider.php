<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Support\Gateways\FakeDriver;
use App\Support\Gateways\GatewayDriverException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base provider for every module in app/Modules.
 *
 * A module declares the tables it owns in TABLES. Architecture tests read this
 * list: no other module may name these tables, and this module's migrations may
 * touch nothing else.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** @var list<string> */
    public const array TABLES = [];

    abstract public static function moduleName(): string;

    public function boot(): void
    {
        $migrations = $this->modulePath('Database/Migrations');

        if (is_dir($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }

        // Views are namespaced by module: view('identity::auth.login').
        $views = $this->modulePath('Resources/views');

        if (is_dir($views)) {
            $this->loadViewsFrom($views, strtolower(static::moduleName()));
        }

        // Browser routes for the fan PWA, inside the "web" middleware group.
        $routes = $this->modulePath('Http/routes.php');

        if (is_file($routes) && ! $this->app->routesAreCached()) {
            Route::middleware('web')->group($routes);
        }
    }

    protected function modulePath(string $path = ''): string
    {
        $base = app_path('Modules/'.static::moduleName());

        return $path === '' ? $base : $base.'/'.$path;
    }

    /**
     * Bind a gateway contract to the driver named in config/fanfolio.php.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @param  array<string, class-string<T>>  $drivers
     */
    protected function bindDriver(string $contract, string $configKey, array $drivers): void
    {
        $this->app->singleton($contract, static function (Application $app) use ($contract, $configKey, $drivers): object {
            $driver = config('fanfolio.drivers.'.$configKey);

            if (! is_string($driver) || ! array_key_exists($driver, $drivers)) {
                throw GatewayDriverException::unknown($contract, $configKey, is_string($driver) ? $driver : null);
            }

            $class = $drivers[$driver];

            if (is_subclass_of($class, FakeDriver::class) && $app->environment('production')) {
                throw GatewayDriverException::fakeInProduction($contract);
            }

            $instance = $app->make($class);

            if (! $instance instanceof $contract) {
                throw GatewayDriverException::unknown($contract, $configKey, $driver);
            }

            return $instance;
        });
    }
}
