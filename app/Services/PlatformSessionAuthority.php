<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Experimental primitive; no route may use this until all session invalidators
 * and the company writer participate in the same transaction and lock order.
 */
final class PlatformSessionAuthority
{
    /** Experimental only; caller must authenticate both principals beforehand. */
    public function registerPrincipalAfterMfa(string $actor, string $marker, int $expiresAt, string $userId): void
    {
        $hash = $this->bindingHash($marker);
        (new WebPrincipalGeneration)->withLockedPrincipal($userId, function (int $generation) use ($actor, $hash, $expiresAt, $userId): void {
            $this->assertAvailable($this->lockActor($actor));
            if ($expiresAt <= now()->getTimestamp()) {
                throw new InvalidArgumentException('Invalid session authorization expiry.');
            }
            DB::table('platform_session_authorizations')->insert([
                'binding_hash' => $hash, 'actor_id' => $actor, 'web_user_id' => $userId,
                'web_generation' => $generation, 'expires_at_epoch' => $expiresAt, 'revoked' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /** Recheck a principal hint only after locking its generation and binding. */
    public function withActivePrincipalSession(string $actor, string $marker, string $sessionId, string $userId, callable $callback): mixed
    {
        $hash = $this->bindingHash($marker);

        return (new WebPrincipalGeneration)->withLockedPrincipal($userId, function (int $generation) use ($actor, $hash, $sessionId, $userId, $callback): mixed {
            $this->lockActor($actor);
            $binding = DB::table('platform_session_authorizations')->where('binding_hash', $hash)
                ->where('actor_id', $actor)->lockForUpdate()->first();
            abort_unless($binding && ! $binding->revoked && (int) $binding->expires_at_epoch > now()->getTimestamp()
                && $binding->web_user_id === $userId && $binding->web_generation !== null
                && (int) $binding->web_generation === $generation, 401, 'Sessão indisponível.');
            $this->lockLaravelSession($sessionId);

            return $callback();
        });
    }

    /**
     * The caller must hold an authenticated platform session and have completed
     * MFA. Marker and lifetime come from trusted server code, never from input.
     */
    public function registerAfterMfa(string $actor, string $marker, int $expiresAt): void
    {
        $hash = $this->bindingHash($marker);
        $this->requireTopLevel();

        DB::transaction(function () use ($actor, $hash, $expiresAt): void {
            $this->assertAvailable($this->lockActor($actor));
            if ($expiresAt <= now()->getTimestamp()) {
                throw new InvalidArgumentException('Invalid session authorization expiry.');
            }
            DB::table('platform_session_authorizations')->insert([
                'binding_hash' => $hash, 'actor_id' => $actor,
                'expires_at_epoch' => $expiresAt, 'revoked' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /** Consume the challenge and replace its session authority in one commit. */
    public function completeMfa(string $actor, string $marker, int $expiresAt, ?string $previousActor, ?string $previousMarker, callable $consume): void
    {
        $hash = $this->bindingHash($marker);
        if (($previousActor === null) !== ($previousMarker === null)) {
            throw new InvalidArgumentException('Previous session context is incomplete.');
        }
        $previousHash = $previousMarker === null ? null : $this->bindingHash($previousMarker);
        if ($previousHash !== null && hash_equals($hash, $previousHash)) {
            throw new InvalidArgumentException('Session authorization must rotate.');
        }
        $this->requireTopLevel();

        DB::transaction(function () use ($actor, $hash, $expiresAt, $previousActor, $previousHash, $consume): void {
            $actors = array_values(array_unique(array_filter([$actor, $previousActor], fn ($id) => $id !== null)));
            sort($actors, SORT_STRING);
            foreach ($actors as $lockedActor) {
                $admin = $this->lockActor($lockedActor);
                if ($lockedActor === $actor) {
                    $this->assertAvailable($admin);
                }
            }
            if ($expiresAt <= now()->getTimestamp()) {
                throw new InvalidArgumentException('Invalid session authorization expiry.');
            }
            if ($previousHash !== null) {
                $previous = DB::table('platform_session_authorizations')->where('actor_id', $previousActor)
                    ->where('binding_hash', $previousHash)->lockForUpdate()->first();
                abort_unless($previous && $previous->web_user_id === null && $previous->web_generation === null, 503, 'Sessão indisponível.');
                DB::table('platform_session_authorizations')->where('actor_id', $previousActor)->where('binding_hash', $previousHash)
                    ->update(['revoked' => true, 'updated_at' => now()]);
            }
            $consume(); // Must perform local writes on the same connection only.
            DB::table('platform_session_authorizations')->insert([
                'binding_hash' => $hash, 'actor_id' => $actor,
                'expires_at_epoch' => $expiresAt, 'revoked' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /** Revoke exactly the presented session; never revoke a newer login. */
    public function revoke(string $actor, string $marker): void
    {
        $hash = $this->bindingHash($marker);
        $this->requireTopLevel();

        DB::transaction(function () use ($actor, $hash): void {
            $this->lockActor($actor);
            DB::table('platform_session_authorizations')->where('actor_id', $actor)
                ->where('binding_hash', $hash)->update(['revoked' => true, 'updated_at' => now()]);
        });
    }

    /** Revoke all bindings for one administrator under the same actor lock. */
    public function revokeAll(string $actor): void
    {
        $this->requireTopLevel();

        DB::transaction(function () use ($actor): void {
            $this->lockActor($actor);
            DB::table('platform_session_authorizations')->where('actor_id', $actor)
                ->where('revoked', false)->update(['revoked' => true, 'updated_at' => now()]);
        });
    }

    /** Rotate only a still-active binding; a stale request cannot renew one. */
    public function rotate(string $actor, string $oldMarker, string $newMarker, int $expiresAt): void
    {
        $oldHash = $this->bindingHash($oldMarker);
        $newHash = $this->bindingHash($newMarker);
        if (hash_equals($oldHash, $newHash)) {
            throw new InvalidArgumentException('Session authorization must rotate.');
        }
        $this->requireTopLevel();

        DB::transaction(function () use ($actor, $oldHash, $newHash, $expiresAt): void {
            $this->assertAvailable($this->lockActor($actor));
            $current = DB::table('platform_session_authorizations')->where('binding_hash', $oldHash)
                ->where('actor_id', $actor)->lockForUpdate()->first();
            $now = now();
            abort_unless($current && ! $current->revoked && (int) $current->expires_at_epoch > $now->getTimestamp(), 401, 'Sessão indisponível.');
            abort_if($current->web_user_id !== null || $current->web_generation !== null, 503, 'Sessão indisponível.');
            if ($expiresAt <= $now->getTimestamp()) {
                throw new InvalidArgumentException('Invalid session authorization expiry.');
            }
            DB::table('platform_session_authorizations')->where('binding_hash', $oldHash)
                ->where('actor_id', $actor)->update(['revoked' => true, 'updated_at' => $now]);
            DB::table('platform_session_authorizations')->insert([
                'binding_hash' => $newHash, 'actor_id' => $actor,
                'expires_at_epoch' => $expiresAt, 'revoked' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    /**
     * The callback must perform only local writes on this same DB connection.
     * Permission and token checks remain mandatory inside that transaction.
     * Expiry is evaluated at lock acquisition; a winner may commit afterwards.
     */
    public function withActiveSession(string $actor, string $marker, string $sessionId, callable $callback): mixed
    {
        $hash = $this->bindingHash($marker);
        $this->requireTopLevel();

        return DB::transaction(function () use ($actor, $hash, $sessionId, $callback): mixed {
            $this->lockActor($actor);
            $binding = DB::table('platform_session_authorizations')->where('binding_hash', $hash)
                ->where('actor_id', $actor)->lockForUpdate()->first();
            abort_unless($binding && ! $binding->revoked && (int) $binding->expires_at_epoch > now()->getTimestamp(), 401, 'Sessão indisponível.');
            abort_if($binding->web_user_id !== null || $binding->web_generation !== null, 503, 'Sessão indisponível.');
            $this->lockLaravelSession($sessionId);

            return $callback();
        });
    }

    /** Atomically revoke the binding and perform a local security mutation. */
    public function retireAndRun(string $actor, string $marker, string $sessionId, callable $callback): mixed
    {
        $hash = $this->bindingHash($marker);

        return $this->withActiveSession($actor, $marker, $sessionId, function () use ($actor, $hash, $callback): mixed {
            DB::table('platform_session_authorizations')->where('actor_id', $actor)->where('binding_hash', $hash)
                ->update(['revoked' => true, 'updated_at' => now()]);

            return $callback();
        });
    }

    /** Expiry and cleanup must serialize with the writer on the same PDO. */
    private function lockLaravelSession(string $sessionId): void
    {
        $table = $this->transactionalSessionTable();
        $lifetime = config('session.lifetime');
        abort_unless(is_int($lifetime) && $lifetime > 0 && preg_match('/\A[A-Za-z0-9]{40}\z/D', $sessionId) === 1,
            503, 'Sessão indisponível.');
        $row = DB::table($table)->where('id', $sessionId)->lockForUpdate()->first(['id', 'last_activity']);
        abort_unless($row && (int) $row->last_activity > now()->subMinutes($lifetime)->getTimestamp(), 401, 'Sessão indisponível.');
    }

    /** Reject non-atomic session topology before any security mutation. */
    public function transactionalSessionTable(): string
    {
        $table = config('session.table', 'sessions');
        abort_unless(config('session.driver') === 'database' && DB::connection(config('session.connection')) === DB::connection()
            && is_string($table) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $table) === 1, 503, 'Sessão indisponível.');

        return $table;
    }

    private function lockActor(string $actor): object
    {
        if ($actor === '' || strlen($actor) > 128) {
            throw new InvalidArgumentException('Invalid session authorization actor.');
        }
        $admin = DB::table('platform_admins')->where('id', $actor)->lockForUpdate()->first(['id', 'status', 'manual_blocked_at', 'deactivated_at', 'locked_until']);
        abort_unless($admin, 401, 'Sessão indisponível.');

        return $admin;
    }

    private function assertAvailable(object $admin): void
    {
        abort_unless($admin->status === 'ativo' && $admin->manual_blocked_at === null
            && $admin->deactivated_at === null && ($admin->locked_until === null || Carbon::parse($admin->locked_until)->isPast()), 401, 'Sessão indisponível.');
    }

    private function bindingHash(string $marker): string
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $marker) !== 1) {
            throw new InvalidArgumentException('Invalid session authorization marker.');
        }

        return hash('sha256', $marker);
    }

    private function requireTopLevel(): void
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Session authorization requires a top-level transaction.');
        }
    }
}
