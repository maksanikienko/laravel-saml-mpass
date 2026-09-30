<?php

namespace Mpass\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired just before the SLO redirect to MPass is issued.
 * Use this to clean up local session data before the user leaves.
 */
final class MpassLogoutInitiated
{
    use Dispatchable;

    public function __construct(
        public ?Authenticatable $user,
        public string $nameId,
        public ?string $sessionIndex,
    ) {}
}
