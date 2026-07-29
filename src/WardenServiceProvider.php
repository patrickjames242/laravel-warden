<?php

declare(strict_types=1);

namespace Warden;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class WardenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/warden.php', 'warden');

        $this->app->singleton(WardenManager::class, fn (Application $app): WardenManager => new WardenManager(
            (array) $app['config']->get('warden.policies', [])
        ));

        /* Warden ships no default resolver; the consumer must configure one. */
        $this->app->bind(PermissionResolver::class, function (Application $app): PermissionResolver {
            $resolverClass = $app['config']->get('warden.permission_resolver');

            if ($resolverClass === null) {
                throw new RuntimeException(
                    'No Warden permission resolver configured. Set warden.permission_resolver to a class implementing '.PermissionResolver::class.'.'
                );
            }

            return $app->make($resolverClass);
        });
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('warden', WardenMiddleware::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/warden.php' => $this->app->configPath('warden.php'),
            ], 'warden-config');
        }
    }
}
