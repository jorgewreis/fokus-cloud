<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Server-side records; endpoint authentication and CSRF remain caller responsibilities. */
final class CompanyIdempotencyStore
{
    public function __construct(
        private readonly CompanyIdempotencyKeys $keys,
        private readonly CompanyIdempotencyAuthorization $authorization,
        private readonly int $maxOutstanding,
        private readonly int $maxIssuesPerWindow,
        private readonly int $issuanceWindowSeconds,
    ) {
        if ($maxOutstanding < 1 || $maxOutstanding > 1000 || $maxIssuesPerWindow < 1 || $issuanceWindowSeconds < 1) {
            throw new InvalidArgumentException('Invalid issuance quota.');
        }
    }

    public function issue(string $actor, string $operation, int $lifetimeSeconds): string
    {
        $this->requireTopLevel();
        $now = now()->getTimestamp();
        $token = $this->keys->issue($actor, $operation, $now, $lifetimeSeconds);
        $claims = $this->keys->verify($token, $actor, $operation, $now);
        DB::transaction(function () use ($token, $actor, $operation, $claims): void {
            $this->authorization->lockAuthorizedActor($actor);
            $decisionNow = now()->getTimestamp();
            $this->keys->verify($token, $actor, $operation, $decisionNow);
            $windowStart = max(0, $decisionNow - $this->issuanceWindowSeconds);
            $issuedInWindow = DB::table('company_idempotency_requests')->where('actor_id', $actor)
                ->where('recorded_at_epoch', '>=', $windowStart)->count();
            abort_if($issuedInWindow >= $this->maxIssuesPerWindow, 429, 'Limite de emissão temporário atingido.');
            $active = DB::table('company_idempotency_requests')->where('actor_id', $actor)
                ->whereIn('state', ['issued', 'bound'])->where('revoked', false)
                ->where('expires_at_epoch', '>', $decisionNow)->count();
            abort_if($active >= $this->maxOutstanding, 429, 'Limite de solicitações pendentes atingido.');
            DB::table('company_idempotency_requests')->insert([
                'token_hash' => hash('sha256', $token),
                'key_id' => $claims['kid'],
                'actor_id' => $actor,
                'operation' => $operation,
                'issued_at_epoch' => $claims['issued_at'],
                'expires_at_epoch' => $claims['expires_at'],
                'recorded_at_epoch' => $decisionNow,
                'state' => 'issued',
                'revoked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $token;
    }

    /** Returns a replay if already completed, otherwise binds the normalized payload. */
    public function bind(string $token, string $actor, string $operation, ?string $target, array $payload, int $now): ?array
    {
        $this->requireTopLevel();
        $claims = $this->keys->verify($token, $actor, $operation, $now);
        $digest = $this->keys->payloadDigest($claims['kid'], $operation, $target, $payload);

        return DB::transaction(function () use ($token, $actor, $operation, $claims, $digest): ?array {
            $row = $this->lockedRecord($token, $actor, $operation, $claims);
            $this->keys->verify($token, $actor, $operation, now()->getTimestamp());
            $this->authorization->lockAuthorizedActor($actor);
            abort_if($row->revoked, 409, 'A chave foi revogada.');
            if ($row->payload_digest !== null && ! hash_equals($row->payload_digest, $digest)) {
                abort(409, 'A chave já está vinculada a outra solicitação.');
            }
            if ($row->state === 'completed') {
                return $this->result($row);
            }
            abort_unless(in_array($row->state, ['issued', 'bound'], true), 503, 'Solicitação indisponível.');
            DB::table('company_idempotency_requests')->where('token_hash', $row->token_hash)->update([
                'state' => 'bound', 'payload_digest' => $digest, 'updated_at' => now(),
            ]);

            return null;
        });
    }

    /**
     * Writer runs under the record lock and must only perform local DB writes on
     * the same connection. The returned id/status are persisted in the same commit.
     *
     * @param  callable(): array{id:string,status:int}  $writer
     * @return array{id:string,status:int}
     */
    public function complete(string $token, string $actor, string $operation, ?string $target, array $payload, callable $writer): array
    {
        $this->requireTopLevel();

        return DB::transaction(function () use ($token, $actor, $operation, $target, $payload, $writer): array {
            $claims = $this->keys->verify($token, $actor, $operation, now()->getTimestamp());
            $digest = $this->keys->payloadDigest($claims['kid'], $operation, $target, $payload);
            $row = $this->lockedRecord($token, $actor, $operation, $claims);
            $this->keys->verify($token, $actor, $operation, now()->getTimestamp());
            $this->authorization->lockAuthorizedActor($actor);
            abort_if($row->revoked, 409, 'A chave foi revogada.');
            abort_unless($row->payload_digest !== null && hash_equals($row->payload_digest, $digest), 409, 'A chave está vinculada a outra solicitação.');
            if ($row->state === 'completed') {
                return $this->result($row);
            }
            abort_unless($row->state === 'bound', 503, 'Solicitação indisponível.');
            $result = $writer();
            if (! is_array($result) || ! isset($result['id'], $result['status']) || ! is_string($result['id']) || $result['id'] === '' || strlen($result['id']) > 128 || ! in_array($result['status'], [200, 201], true)) {
                throw new InvalidArgumentException('Invalid result.');
            }
            DB::table('company_idempotency_requests')->where('token_hash', $row->token_hash)->update([
                'state' => 'completed', 'result_id' => $result['id'], 'result_status' => $result['status'], 'updated_at' => now(),
            ]);

            return ['id' => $result['id'], 'status' => $result['status']];
        });
    }

    /** Status never proves an absent or uncompleted request will not later commit. */
    public function status(string $token, string $actor, string $operation, int $now): array
    {
        $this->requireTopLevel();
        $claims = $this->keys->verifyForStatus($token, $actor, $operation, $now);

        return DB::transaction(function () use ($token, $actor, $operation, $claims): array {
            $row = DB::table('company_idempotency_requests')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            $this->keys->verifyForStatus($token, $actor, $operation, now()->getTimestamp());
            $this->authorization->lockAuthorizedActor($actor);
            if (! $row) {
                return ['state' => 'absent', 'revoked' => null];
            }
            $this->assertRecord($row, $actor, $operation, $claims);
            abort_unless(in_array($row->state, ['issued', 'bound', 'completed'], true), 503, 'Solicitação indisponível.');
            $result = ['state' => $row->state === 'bound' ? 'observed' : $row->state, 'revoked' => (bool) $row->revoked];
            if ($row->state === 'completed') {
                $result['result'] = $this->result($row);
            }

            return $result;
        });
    }

    /** Revoke an individual token under its lock. The callback records audit in this transaction. */
    public function revoke(string $token, string $actor, string $operation, int $now, callable $audit): array
    {
        $this->requireTopLevel();
        $claims = $this->keys->verifyForStatus($token, $actor, $operation, $now);

        return DB::transaction(function () use ($token, $actor, $operation, $claims, $audit): array {
            $row = $this->lockedRecord($token, $actor, $operation, $claims);
            $this->keys->verifyForStatus($token, $actor, $operation, now()->getTimestamp());
            $this->authorization->lockAuthorizedActor($actor);
            abort_unless(in_array($row->state, ['issued', 'bound', 'completed'], true), 503, 'Solicitação indisponível.');
            if (! $row->revoked) {
                DB::table('company_idempotency_requests')->where('token_hash', $row->token_hash)->update(['revoked' => true, 'updated_at' => now()]);
                $audit();
            }

            return ['state' => $row->state === 'bound' ? 'observed' : $row->state, 'revoked' => true];
        });
    }

    private function lockedRecord(string $token, string $actor, string $operation, array $claims): object
    {
        $row = DB::table('company_idempotency_requests')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
        abort_unless($row, 503, 'Solicitação indisponível.');
        $this->assertRecord($row, $actor, $operation, $claims);

        return $row;
    }

    private function assertRecord(object $row, string $actor, string $operation, array $claims): void
    {
        abort_unless($row->actor_id === $actor && $row->operation === $operation && $row->key_id === $claims['kid']
            && (int) $row->issued_at_epoch === $claims['issued_at'] && (int) $row->expires_at_epoch === $claims['expires_at'], 503, 'Solicitação indisponível.');
    }

    private function result(object $row): array
    {
        abort_unless(is_string($row->result_id) && $row->result_id !== '' && in_array((int) $row->result_status, [200, 201], true), 503, 'Solicitação indisponível.');

        return ['id' => $row->result_id, 'status' => (int) $row->result_status];
    }

    private function requireTopLevel(): void
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new \LogicException('Company idempotency requires a top-level transaction.');
        }
    }
}
