<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/** Experimental and disabled by default; route guards remain authoritative. */
final class PlatformSessionLifecycle
{
    private readonly PlatformSessionBinding $binding;

    public function __construct(private readonly PlatformSessionAuthority $authority)
    {
        $this->binding = new PlatformSessionBinding;
    }

    /** Called only after successful MFA. A previous actor is retired first. */
    public function authenticated(Session $session, string $actor, ?string $previousActor, ?callable $consumeChallenge = null): void
    {
        $expiresAt = $this->enabled() ? $this->expiry() : null;
        $marker = bin2hex(random_bytes(32));
        if ($expiresAt !== null) {
            if ($consumeChallenge === null) {
                throw new \LogicException('MFA challenge consumption is required.');
            }
            $previousMarker = $this->binding->current($session);
            $this->authority->completeMfa($actor, $marker, $expiresAt, $previousMarker === null ? null : $previousActor, $previousMarker, $consumeChallenge);
        } elseif ($consumeChallenge !== null) {
            $consumeChallenge();
        }
        $session->put(PlatformSessionBinding::SESSION_KEY, $marker);
    }

    /** Initial issuance only; replacements remain closed pending transition serialization. */
    public function authenticatedFromGuards(Session $session, string $actor, ?string $previousActor, callable $consumeChallenge): void
    {
        if (! $this->enabled()) {
            $this->authenticated($session, $actor, $previousActor, $consumeChallenge);

            return;
        }
        abort_if($this->binding->current($session) !== null || $previousActor !== null, 503, 'Sessão indisponível.');
        $webUserId = Auth::guard('web')->id();
        $snapshot = $session->get('platform_web_principal_snapshot');
        if ($webUserId !== null) {
            abort_unless(is_array($snapshot) && ($snapshot['user_id'] ?? null) === (string) $webUserId
                && is_int($snapshot['generation'] ?? null) && $snapshot['generation'] >= 0, 503, 'Sessão indisponível.');
        }
        $marker = bin2hex(random_bytes(32));
        $this->authority->completeInitialMfaForPrincipal($actor, $marker, $this->expiry(), $webUserId === null ? null : (string) $webUserId, $webUserId === null ? null : $snapshot['generation'], $consumeChallenge);
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

    public function needsTransactionalRetirement(Session $session): bool
    {
        return $this->enabled() && $this->binding->current($session) !== null;
    }

    public function enabledForSecurityMutation(): bool
    {
        return $this->enabled();
    }

    /** A captured snapshot is not itself proof of an atomic session transition. */
    public function captureWebLogin(User $user): ?array
    {
        return $this->enabled() ? (new WebPrincipalGeneration)->snapshotForLogin($user) : null;
    }

    public function ensurePasswordResetReady(): void
    {
        // The database session's user_id is derived from the default guard and
        // cannot identify all web principals in mixed or support sessions.
        abort_if($this->enabled(), 503, 'Recuperação de senha temporariamente indisponível.');
    }

    /** Check before registration writes or notification while transition is incomplete. */
    public function ensureRegistrationReady(): void
    {
        abort_if($this->enabled(), 503, 'Cadastro temporariamente indisponível nesta sessão.');
    }

    /** Token workflows remain closed until their writes and login are atomic. */
    public function ensureTokenLoginReady(): void
    {
        abort_if($this->enabled(), 503, 'Acesso temporariamente indisponível.');
    }

    /** Invitation delivery is closed until an atomic delivery protocol exists. */
    public function ensureInvitationIssuanceReady(): void
    {
        abort_if($this->enabled(), 503, 'Convites temporariamente indisponíveis.');
    }

    public function transactionalSessionTable(): string
    {
        abort_unless($this->enabled(), 503, 'Sessão indisponível.');

        return $this->authority->transactionalSessionTable();
    }

    /** The callback may only mutate the business database on this connection. */
    public function retireAndRun(Session $session, ?string $actor, callable $callback): mixed
    {
        $marker = $this->binding->current($session);
        abort_unless($this->enabled() && $marker !== null && $actor !== null && $actor !== '', 503, 'Sessão indisponível.');
        $result = $this->authority->retireAndRun($actor, $marker, $session->getId(), $callback);
        $session->forget(PlatformSessionBinding::SESSION_KEY);

        return $result;
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
            // Real guard context is not yet certified by the experimental issuer.
            abort(503, 'Acesso interno temporariamente indisponível.');
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
