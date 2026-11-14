<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;

/** Opaque, non-authenticating marker; never derive it from the session ID. */
final class PlatformSessionBinding
{
    public const SESSION_KEY = 'platform_reconciliation_binding';

    public function rotate(Session $session): void
    {
        $session->put(self::SESSION_KEY, bin2hex(random_bytes(32)));
    }
}
