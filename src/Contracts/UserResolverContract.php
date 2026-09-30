<?php

namespace Mpass\Contracts;

use Mpass\SamlUser;
use Illuminate\Contracts\Auth\Authenticatable;

interface UserResolverContract
{
    /**
     * Find or provision an Authenticatable user from the MPass SAML attributes.
     *
     * Return null to reject the login (the controller will redirect to the
     * failure URL with an appropriate error).
     */
    public function resolve(SamlUser $samlUser): ?Authenticatable;
}
