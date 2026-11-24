<?php

namespace App\Services;

use Illuminate\Http\Request;
use Throwable;

/** Orchestrates a prepared local write; it is not registered to an API route. */
final class CompanyIdempotencyMutation
{
    public function __construct(private readonly CompanyIdempotencyStore $store) {}

    /**
     * Both callbacks must use already structurally validated, normalized data.
     * The preflight runs outside the transaction. The writer must perform only
     * local writes on the store's connection, with audit in that transaction.
     *
     * @param  callable(): void  $preflight
     * @param  callable(): array{id:string,status:int}  $writer
     * @return array{id:string,status:int}
     */
    public function run(string $token, string $actor, string $operation, ?string $target, array $payload, callable $preflight, callable $writer, ?Request $request = null): array
    {
        $replay = $this->store->bind($token, $actor, $operation, $target, $payload, now()->getTimestamp(), $request);
        if ($replay !== null) {
            return $replay;
        }

        try {
            $preflight();
        } catch (Throwable) {
            return $this->resolveOrUncertain($token, $actor, $operation, $target, $payload, $request);
        }

        try {
            return $this->store->complete($token, $actor, $operation, $target, $payload, $writer, $request);
        } catch (Throwable) {
            // A writer's rolled-back error also cannot rule out a matching
            // request that is waiting and could succeed after the lock releases.
            return $this->resolveOrUncertain($token, $actor, $operation, $target, $payload, $request);
        }
    }

    private function resolveOrUncertain(string $token, string $actor, string $operation, ?string $target, array $payload, ?Request $request): array
    {
        // Recheck under lock; an unavailable store must never be treated as
        // proof that the mutation did not happen. Another matching request may
        // still commit later, even when this attempt has failed.
        $replay = $this->store->bind($token, $actor, $operation, $target, $payload, now()->getTimestamp(), $request);
        if ($replay !== null) {
            return $replay;
        }
        abort(503, 'Não foi possível confirmar o resultado. Consulte a situação antes de tentar novamente.');
    }
}
