<?php

namespace Mpass\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Mpass\Tests\TestCase;

class RoutesTest extends TestCase
{
    public function test_package_routes_are_registered(): void
    {
        $expected = [
            'mpass.login' => ['auth/mpass/login', ['GET', 'HEAD']],
            'mpass.acs'   => ['auth/mpass/acs', ['GET', 'HEAD', 'POST']],
            'mpass.slo'   => ['auth/mpass/slo', ['GET', 'HEAD']],
            'mpass.sls'   => ['auth/mpass/sls', ['GET', 'HEAD', 'POST']],
        ];

        foreach ($expected as $name => [$uri, $methods]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route {$name} is not registered.");
            $this->assertSame($uri, $route->uri());
            $this->assertEqualsCanonicalizing($methods, $route->methods());
            $this->assertContains('web', $route->middleware());
        }
    }

    public function test_prefix_and_middleware_are_configurable(): void
    {
        $route = Route::getRoutes()->getByName('mpass.login');

        $this->assertSame('sso/login', $route->uri());
        $this->assertSame(['web', 'throttle:10,1'], $route->middleware());
    }

    public function test_routes_are_not_registered_when_disabled(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('mpass.login'));
        $this->assertNull(Route::getRoutes()->getByName('mpass.acs'));
    }

    /** Routes are registered while booting, so per-test config must be set here. */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        match ($this->name()) {
            'test_prefix_and_middleware_are_configurable' => $app['config']->set('mpass.routes', [
                'enabled'    => true,
                'prefix'     => 'sso',
                'middleware' => ['web', 'throttle:10,1'],
            ]),
            'test_routes_are_not_registered_when_disabled' => $app['config']->set('mpass.routes.enabled', false),
            default => null,
        };
    }
}
