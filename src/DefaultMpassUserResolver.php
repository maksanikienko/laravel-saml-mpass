<?php

namespace Mpass;

use Mpass\Contracts\UserResolverContract;
use Mpass\SamlUser;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Default resolver shipped with the package.
 *
 * Extend this class in your project via `php artisan mpass:publish-resolver`
 * and override the methods to match your users table schema.
 */
class DefaultMpassUserResolver implements UserResolverContract
{
    public function resolve(SamlUser $samlUser): ?Authenticatable
    {
        $userClass = config('auth.providers.users.model', \App\Models\User::class);

        /** @var \Illuminate\Database\Eloquent\Model&Authenticatable $user */
        $user = $userClass::firstOrCreate(
            $this->findBy($samlUser),
            $this->newUserAttributes($samlUser),
        );

        return $this->isAllowed($user) ? $user : null;
    }

    /**
     * Columns used to look up an existing user.
     *
     * The `idnp` column is REQUIRED in your users table — it is the unique
     * identifier returned by MPass (the user's national ID number).
     * Add it to your migration if it does not exist:
     *   $table->string('idnp')->unique();
     *
     * @return array<string, mixed>
     */
    protected function findBy(SamlUser $samlUser): array
    {
        return ['idnp' => $samlUser->nameId];
    }

    /**
     * Attributes written only when creating a NEW user.
     *
     * Override this method in your MpassUserResolver to match your users table.
     * Add or remove fields depending on which columns your table has.
     *
     * @return array<string, mixed>
     */
    protected function newUserAttributes(SamlUser $samlUser): array
    {
        return [
            'password' => null,
        ];
    }

    /**
     * Return false to deny login (e.g. banned or inactive accounts).
     */
    protected function isAllowed(Authenticatable $user): bool
    {
        return true;
    }
}
