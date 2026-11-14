<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Experimental primitive; not connected to authentication or public routes. */
final class WebPrincipalGeneration
{
    /**
     * Serialize a local mutation with principal generation changes.
     * The callback must only use the same database connection and transaction.
     */
    public function withLockedPrincipal(string $userId, callable $callback): mixed
    {
        if ($userId === '' || strlen($userId) > 128) {
            throw new InvalidArgumentException('Invalid web principal.');
        }
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Principal generation requires a top-level transaction.');
        }

        return DB::transaction(function () use ($userId, $callback): mixed {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id', 'status', 'locked_until', 'password']);
            abort_unless($user, 401, 'Sessão indisponível.');
            $generation = DB::table('web_principal_generations')->where('user_id', $userId)->first();

            return $callback($generation === null ? 0 : (int) $generation->generation, $user);
        });
    }

    /** Capture only if credentials observed by the login still match the locked row. */
    public function snapshotForLogin(User $user): array
    {
        $id = (string) $user->getKey();
        $observedPassword = $user->getRawOriginal('password');

        return $this->withLockedPrincipal($id, function (int $generation, object $locked) use ($id, $observedPassword): array {
            abort_unless($locked->status === 'ativa' && ($locked->locked_until === null || Carbon::parse($locked->locked_until)->isPast())
                && is_string($observedPassword) && is_string($locked->password)
                && hash_equals($locked->password, $observedPassword), 401, 'Sessão indisponível.');

            return ['user_id' => $id, 'generation' => $generation];
        });
    }

    /** Advance and perform a local mutation in one commit. */
    public function advance(string $userId, callable $callback): mixed
    {
        return $this->withLockedPrincipal($userId, function (int $generation) use ($userId, $callback): mixed {
            if ($generation === PHP_INT_MAX) {
                throw new LogicException('Principal generation exhausted.');
            }
            DB::table('web_principal_generations')->updateOrInsert(
                ['user_id' => $userId],
                ['generation' => $generation + 1, 'updated_at' => now()]
            );

            return $callback();
        });
    }
}
