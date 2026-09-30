<?php

namespace Mpass\Facades;

use Mpass\Contracts\UserResolverContract;
use Mpass\MpassManager;
use Mpass\SamlUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void        login(?string $returnTo = null)
 * @method static void        processResponse()
 * @method static void        processSLO()
 * @method static void        logout(?string $returnTo, string $nameId, string $sessionIndex)
 * @method static array       getErrors()
 * @method static bool        isAuthenticated()
 * @method static array       getAttributes()
 * @method static string      getNameId()
 * @method static string|null getSessionIndex()
 * @method static string      getMetadata()
 *
 * @see MpassManager
 */
class Mpass extends Facade
{
    /**
     * Register a closure that resolves the local user from SAML attributes.
     *
     * Usage in AppServiceProvider::boot():
     *
     *   Mpass::resolveUsersUsing(function (SamlUser $samlUser): ?User {
     *       return User::where('idnp', $samlUser->nameId)->first();
     *   });
     */
    public static function resolveUsersUsing(callable $resolver): void
    {
        $closure = \Closure::fromCallable($resolver);

        app()->bind(UserResolverContract::class, fn () => new class ($closure) implements UserResolverContract {
            public function __construct(private \Closure $resolver) {}

            public function resolve(SamlUser $samlUser): ?Authenticatable
            {
                return ($this->resolver)($samlUser);
            }
        });
    }

    protected static function getFacadeAccessor(): string
    {
        return MpassManager::class;
    }
}
