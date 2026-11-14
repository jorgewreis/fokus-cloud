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

    /** Missing or malformed bindings are never silently provisioned. */
    public function current(Session $session): ?string
    {
        $value = $session->get(self::SESSION_KEY);

        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1 ? $value : null;
    }

    public function rotateIfPresent(Session $session): void
    {
        if ($this->current($session) === null) {
            $session->forget(self::SESSION_KEY);

            return;
        }
        $this->rotate($session);
    }
}
