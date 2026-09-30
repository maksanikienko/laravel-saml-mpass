<?php

namespace Mpass\Events;

use Mpass\SamlUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a SAML response has been verified and a local user has been
 * resolved. Listen to this event to apply post-login logic such as activity
 * logging, role synchronisation, or session enrichment.
 */
final class MpassAuthenticated
{
    use Dispatchable;

    public function __construct(
        public Authenticatable $user,
        public SamlUser $samlUser,
    ) {}
}
