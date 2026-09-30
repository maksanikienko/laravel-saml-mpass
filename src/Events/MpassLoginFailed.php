<?php

namespace Mpass\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when the SAML authentication flow fails — either because MPass
 * returned an error, the response validation failed, or the user resolver
 * returned null.
 */
final class MpassLoginFailed
{
    use Dispatchable;

    public function __construct(
        public string $reason,
        public array $errors = [],
    ) {}
}
