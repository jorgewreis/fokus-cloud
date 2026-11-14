<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;

/** Experimental and disabled by default; route guards remain authoritative. */
final class PlatformSessionLifecycle
{
    private readonly PlatformSessionBinding $binding;

    public function __construct(private readonly PlatformSessionAuthority $authority)
    {
        $this->binding = new PlatformSessionBinding;
    }

    /** Called only after successful MFA. A previous actor is retired first. */
    public function authenticated(Session $session, string $actor, ?string $previousActor): void
    {
        $expiresAt = $this->enabled() ? $this->expiry() : null;
        $this->retire($session, $previousActor);
        $marker = bin2hex(random_bytes(32));
        if ($expiresAt !== null) {
            $this->authority->registerAfterMfa($actor, $marker, $expiresAt);
        }
        $session->put(PlatformSessionBinding::SESSION_KEY, $marker);
    }

    /** Retire before invalidating the shared Laravel session. */
    public function retire(Session $session, ?string $actor): void
    {
        $marker = $this->binding->current($session);
        if ($marker !== null && $this->enabled()) {
            abort_unless($actor !== null && $actor !== '', 503, 'Sessão indisponível.');
            $this->authority->revoke($actor, $marker);
        }
        $session->forget(PlatformSessionBinding::SESSION_KEY);
    }

    /** Context changes never provision a binding for an older session. */
    public function rotateIfPresent(Session $session, ?string $actor): void
    {
        $old = $this->binding->current($session);
        if ($old === null) {
            $session->forget(PlatformSessionBinding::SESSION_KEY);

            return;
        }
        $new = bin2hex(random_bytes(32));
        if ($this->enabled()) {
            abort_unless($actor !== null && $actor !== '', 503, 'Sessão indisponível.');
            $this->authority->rotate($actor, $old, $new, $this->expiry());
        }
        $session->put(PlatformSessionBinding::SESSION_KEY, $new);
    }

    /** Do not change legacy portal/support behavior while this prototype is off. */
    public function rotateIfEnabled(Session $session, ?string $actor): void
    {
        if ($this->enabled()) {
            $this->rotateIfPresent($session, $actor);
        }
    }

    public function revokeAll(string $actor): void
    {
        if ($this->enabled()) {
            $this->authority->revokeAll($actor);
        }
    }

    /** Check before consuming a one-time MFA challenge. */
    public function ensureConfigurationReady(): void
    {
        if ($this->enabled()) {
            $this->expiry();
        }
    }

    private function enabled(): bool
    {
        return config('company_idempotency.session_authority_lifecycle', false) === true;
    }

    private function expiry(): int
    {
        $seconds = config('company_idempotency.session_authority_max_age_seconds');
        if (is_string($seconds) && preg_match('/\A[1-9][0-9]{0,7}\z/D', $seconds) === 1) {
            $seconds = (int) $seconds;
        }
        if (! is_int($seconds) || $seconds < 1 || $seconds > 31536000) {
            throw new \LogicException('Invalid session authority lifetime.');
        }

        return now()->addSeconds($seconds)->getTimestamp();
    }
}
