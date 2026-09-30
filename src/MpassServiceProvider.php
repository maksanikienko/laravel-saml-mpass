<?php

namespace Mpass;

use Mpass\Console\PublishResolverCommand;
use Mpass\Contracts\UserResolverContract;
use Mpass\Http\Controllers\MpassController;
use Illuminate\Support\ServiceProvider;

class MpassServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mpass.php', 'mpass');

        $this->app->singleton(MpassManager::class, function ($app) {
            return new MpassManager($app['config']['mpass']);
        });

        // Register a sensible default so the package works without manual wiring.
        // Projects override this by binding UserResolverContract in their own provider.
        $this->app->bindIf(UserResolverContract::class, DefaultMpassUserResolver::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/mpass.php' => config_path('mpass.php'),
        ], 'mpass-config');

        if ($this->app->runningInConsole()) {
            $this->commands([PublishResolverCommand::class]);
        }

        if ($this->app['config']['mpass.routes.enabled'] ?? true) {
            $this->loadRoutes();
        }
    }

    private function loadRoutes(): void
    {
        $config = $this->app['config']['mpass.routes'];

        $this->app['router']
            ->middleware($config['middleware'] ?? ['web'])
            ->prefix($config['prefix'] ?? 'auth/mpass')
            ->group(function () {
                $this->app['router']->get('login', [MpassController::class, 'login'])
                    ->name('mpass.login');

                $this->app['router']->match(['get', 'post'], 'acs', [MpassController::class, 'acs'])
                    ->name('mpass.acs');

                $this->app['router']->get('slo', [MpassController::class, 'slo'])
                    ->name('mpass.slo');

                $this->app['router']->match(['get', 'post'], 'sls', [MpassController::class, 'sls'])
                    ->name('mpass.sls');
            });
    }
}
