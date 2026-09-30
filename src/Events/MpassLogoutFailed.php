<?php

namespace Mpass\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when MPass answers an SP-initiated logout with an error or a
 * non-success status. The local session has already been terminated.
 */
final class MpassLogoutFailed
{
    use Dispatchable;

    public function __construct(
        public string $reason,
        public array $errors = [],
    ) {}
}
