<?php

namespace Mpass\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after the SLS (Single Logout Service) callback from MPass has been
 * processed successfully and the local session has been terminated.
 */
final class MpassLogoutCompleted
{
    use Dispatchable;
}
