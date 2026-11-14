<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Isolated experiment only; no routes, workers or delivery are connected. */
final class InvitationDeliveryOutbox
{
    public function __construct(private readonly Encrypter $encrypter) {}

    /** Stage an existing invitation and encrypted delivery intent atomically. */
    public function stage(string $companyId, string $userId, string $membershipId, string $invitationId, string $requestKey, int $expiresAt, string $issuer): string
    {
        if ($companyId === '' || $userId === '' || $membershipId === '' || $invitationId === ''
            || preg_match('/\A[a-f0-9]{64}\z/D', $requestKey) !== 1
            || $issuer !== 'platform') {
            throw new InvalidArgumentException('Invalid invitation delivery request.');
        }
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Invitation staging requires a top-level transaction.');
        }

        return DB::transaction(fn (): string => $this->stageLocked($companyId, $userId, $membershipId, $invitationId, $requestKey, $expiresAt, $issuer));
    }

    /**
     * Experimental composition only: both callbacks must perform local work
     * on the same connection, with no external effects. Authorization runs on
     * every request, including replay, while the company row is locked.
     * The caller supplies canonical intent covering every callback mutation.
     */
    public function composePortal(string $companyId, string $cpf, string $email, string $requestKey, int $expiresAt, array $intent, PortalInvitationAuthorization $authorize, PortalInvitationGraphWriter $createGraph): string
    {
        if ($companyId === '' || $cpf === '' || $email === '' || preg_match('/\A[a-f0-9]{64}\z/D', $requestKey) !== 1) {
            throw new InvalidArgumentException('Invalid portal invitation request.');
        }
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Portal invitation requires a top-level transaction.');
        }
        $intentHash = hash_hmac('sha256', json_encode([$companyId, $cpf, mb_strtolower($email), $expiresAt, $this->canonicalIntent($intent)], JSON_THROW_ON_ERROR), $this->encrypter->getKey());

        return DB::transaction(function () use ($companyId, $cpf, $email, $requestKey, $expiresAt, $intentHash, $authorize, $createGraph): string {
            $company = DB::table('companies')->where('id', $companyId)->where('status', 'ativa')->whereNull('deleted_at')->lockForUpdate()->first(['id']);
            abort_unless($company, 422, 'Convite indisponível.');
            abort_unless($authorize->check($companyId) === true, 403, 'Acesso indisponível.');
            $prior = DB::table('invitation_delivery_outbox')->where('request_hash', hash('sha256', $requestKey))->first();
            if ($prior) {
                abort_unless($prior->company_id === $companyId && is_string($prior->intent_hash) && hash_equals($prior->intent_hash, $intentHash), 409, 'Solicitação indisponível.');
                $user = DB::table('users')->where('id', $prior->user_id)->where('cpf', $cpf)->lockForUpdate()->first(['id', 'email']);
                abort_unless($user && mb_strtolower($user->email) === mb_strtolower($email), 409, 'Solicitação indisponível.');
                $id = $this->stageLocked($companyId, $prior->user_id, $prior->membership_id, $prior->invitation_id, $requestKey, $expiresAt, $prior->issuer);
                $lockedIntent = DB::table('invitation_delivery_outbox')->where('id', $id)->lockForUpdate()->value('intent_hash');
                abort_unless(is_string($lockedIntent) && hash_equals($lockedIntent, $intentHash), 409, 'Solicitação indisponível.');

                return $id;
            }

            $existing = DB::table('users')->where('cpf', $cpf)->lockForUpdate()->first(['id', 'email']);
            if ($existing) {
                abort_unless(mb_strtolower($existing->email) === mb_strtolower($email), 409, 'Solicitação indisponível.');
            }
            $graph = $createGraph->create($existing?->id);
            abort_unless(is_array($graph) && is_string($graph['user_id'] ?? null) && is_string($graph['membership_id'] ?? null)
                && is_string($graph['invitation_id'] ?? null) && (! $existing || $graph['user_id'] === $existing->id), 409, 'Solicitação indisponível.');
            $createdUser = DB::table('users')->where('id', $graph['user_id'])->where('cpf', $cpf)->lockForUpdate()->first(['id', 'email']);
            abort_unless($createdUser && mb_strtolower($createdUser->email) === mb_strtolower($email), 409, 'Solicitação indisponível.');
            $id = $this->stageLocked($companyId, $graph['user_id'], $graph['membership_id'], $graph['invitation_id'], $requestKey, $expiresAt, $existing ? 'portal_existing' : 'portal_new');
            DB::table('invitation_delivery_outbox')->where('id', $id)->update(['intent_hash' => $intentHash, 'updated_at' => now()]);

            return $id;
        });
    }

    private function canonicalIntent(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $this->canonicalIntent($item);
            }

            return $value;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Unsupported portal invitation intent.');
    }

    private function stageLocked(string $companyId, string $userId, string $membershipId, string $invitationId, string $requestKey, int $expiresAt, string $issuer): string
    {
        if (DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('Invitation staging requires its owning transaction.');
        }
        $company = DB::table('companies')->where('id', $companyId)->where('status', 'ativa')->whereNull('deleted_at')->lockForUpdate()->first(['id']);
        abort_unless($company, 422, 'Convite indisponível.');
        $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id', 'status', 'locked_until', 'email', 'email_verified_at']);
        abort_unless($user && in_array($user->status, ['ativa', 'pendente'], true) && is_string($user->email) && $user->email !== ''
            && ($user->locked_until === null || Carbon::parse($user->locked_until)->isPast()), 422, 'Convite indisponível.');
        // Issuer and existing-account provenance must be established by trusted server code.
        $purpose = match ($issuer) {
            'platform' => $user->email_verified_at === null ? 'password_creation' : 'membership_acceptance',
            'portal_existing' => 'membership_acceptance',
            'portal_new' => 'password_creation',
        };
        $membership = DB::table('company_memberships')->where('id', $membershipId)->where('company_id', $companyId)
            ->where('user_id', $userId)->where('status', 'pendente')->whereNull('deleted_at')->lockForUpdate()->first(['id']);
        abort_unless($membership, 422, 'Convite indisponível.');
        $invitation = DB::table('company_invitations')->where('id', $invitationId)->where('company_id', $companyId)
            ->where('membership_id', $membershipId)->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->first(['id', 'expires_at']);
        abort_unless($invitation && $expiresAt > now()->getTimestamp() && $expiresAt <= Carbon::parse($invitation->expires_at)->getTimestamp(), 422, 'Convite indisponível.');
        $existing = DB::table('invitation_delivery_outbox')->where('request_hash', hash('sha256', $requestKey))->lockForUpdate()->first();
        if ($existing) {
            abort_unless($existing->company_id === $companyId && $existing->user_id === $userId && $existing->membership_id === $membershipId
                && $existing->invitation_id === $invitationId && $existing->issuer === $issuer
                && (int) $existing->expires_at_epoch === $expiresAt, 409, 'Solicitação indisponível.');
            $envelope = json_decode($this->encrypter->decrypt($existing->ciphertext, false), true);
            abort_unless(is_array($envelope) && ($envelope['recipient'] ?? null) === $user->email, 409, 'Solicitação indisponível.');
            $existingToken = DB::table('security_tokens')->where('id', $existing->security_token_id)->where('user_id', $userId)
                ->where('purpose', $purpose)->whereNull('used_at')->where('expires_at', '>', now())->lockForUpdate()->first(['token_hash']);
            abort_unless($existing->state === 'pending' && $existingToken && is_string($envelope['token'] ?? null)
                && hash_equals($existingToken->token_hash, hash('sha256', $envelope['token'])), 409, 'Solicitação indisponível.');

            return (string) $existing->id;
        }
        // Reissue policy is not settled: block another staged active token for this invitation.
        $activeTokens = DB::table('invitation_delivery_outbox as outbox')->join('security_tokens as token', 'token.id', '=', 'outbox.security_token_id')
            ->where('outbox.invitation_id', $invitationId)->whereNull('token.used_at')->where('token.expires_at', '>', now())
            ->lockForUpdate()->get(['token.id']);
        abort_if($activeTokens->isNotEmpty(), 409, 'Solicitação indisponível.');
        $plain = bin2hex(random_bytes(32));
        $tokenId = PrefixedUlid::make('TKN');
        $outboxId = PrefixedUlid::make('OUT');
        DB::table('security_tokens')->insert([
            'id' => $tokenId, 'user_id' => $userId, 'purpose' => $purpose, 'token_hash' => hash('sha256', $plain),
            'payload' => json_encode(['membership_id' => $membershipId, 'invitation_id' => $invitationId], JSON_THROW_ON_ERROR),
            'expires_at' => Carbon::createFromTimestampUTC($expiresAt), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('invitation_delivery_outbox')->insert([
            'id' => $outboxId, 'request_hash' => hash('sha256', $requestKey), 'company_id' => $companyId, 'user_id' => $userId,
            'membership_id' => $membershipId, 'invitation_id' => $invitationId, 'security_token_id' => $tokenId,
            'issuer' => $issuer,
            'expires_at_epoch' => $expiresAt, 'ciphertext' => $this->encrypter->encrypt(json_encode(['token' => $plain, 'recipient' => $user->email], JSON_THROW_ON_ERROR), false),
            'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $outboxId;
    }
}
