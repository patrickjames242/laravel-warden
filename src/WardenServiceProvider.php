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
            (array) $app['config']->get('warden.schemas', [])
        ));

        /* Warden ships no default resolver; the consumer must configure one. */
        $this->app->bind(RuleResolver::class, function (Application $app): RuleResolver {
            $resolverClass = $app['config']->get('warden.rule_resolver');

            if ($resolverClass === null) {
                throw new RuntimeException(
                    'No Warden rule resolver configured. Set warden.rule_resolver to a class implementing '.RuleResolver::class.'.'
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
