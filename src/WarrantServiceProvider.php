<?php

declare(strict_types=1);

namespace Warrant;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class WarrantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/warrant.php', 'warrant');

        $this->app->singleton(WarrantManager::class, fn (Application $app): WarrantManager => new WarrantManager(
            (array) $app['config']->get('warrant.schemas', [])
        ));

        /* Warrant ships no default resolver; the consumer must configure one. */
        $this->app->bind(RuleResolver::class, function (Application $app): RuleResolver {
            $resolverClass = $app['config']->get('warrant.rule_resolver');

            if ($resolverClass === null) {
                throw new RuntimeException(
                    'No Warrant rule resolver configured. Set warrant.rule_resolver to a class implementing '.RuleResolver::class.'.'
                );
            }

            return $app->make($resolverClass);
        });
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('warrant', WarrantMiddleware::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/warrant.php' => $this->app->configPath('warrant.php'),
            ], 'warrant-config');
        }
    }
}
