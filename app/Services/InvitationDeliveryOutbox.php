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
    public function stage(string $companyId, string $userId, string $membershipId, string $invitationId, string $requestKey, int $expiresAt): string
    {
        if ($companyId === '' || $userId === '' || $membershipId === '' || $invitationId === ''
            || preg_match('/\A[a-f0-9]{64}\z/D', $requestKey) !== 1) {
            throw new InvalidArgumentException('Invalid invitation delivery request.');
        }
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Invitation staging requires a top-level transaction.');
        }

        return DB::transaction(function () use ($companyId, $userId, $membershipId, $invitationId, $requestKey, $expiresAt): string {
            $company = DB::table('companies')->where('id', $companyId)->where('status', 'ativa')->whereNull('deleted_at')->lockForUpdate()->first(['id']);
            abort_unless($company, 422, 'Convite indisponível.');
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id', 'status', 'locked_until', 'email', 'email_verified_at']);
            abort_unless($user && in_array($user->status, ['ativa', 'pendente'], true) && is_string($user->email) && $user->email !== ''
                && ($user->locked_until === null || Carbon::parse($user->locked_until)->isPast()), 422, 'Convite indisponível.');
            // Match the existing platform issuer; the password-creation consumer remains gated.
            $purpose = $user->email_verified_at === null ? 'password_creation' : 'membership_acceptance';
            $membership = DB::table('company_memberships')->where('id', $membershipId)->where('company_id', $companyId)
                ->where('user_id', $userId)->where('status', 'pendente')->whereNull('deleted_at')->lockForUpdate()->first(['id']);
            abort_unless($membership, 422, 'Convite indisponível.');
            $invitation = DB::table('company_invitations')->where('id', $invitationId)->where('company_id', $companyId)
                ->where('membership_id', $membershipId)->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->first(['id', 'expires_at']);
            abort_unless($invitation && $expiresAt > now()->getTimestamp() && $expiresAt <= Carbon::parse($invitation->expires_at)->getTimestamp(), 422, 'Convite indisponível.');
            $existing = DB::table('invitation_delivery_outbox')->where('request_hash', hash('sha256', $requestKey))->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->company_id === $companyId && $existing->user_id === $userId && $existing->membership_id === $membershipId
                    && $existing->invitation_id === $invitationId && (int) $existing->expires_at_epoch === $expiresAt, 409, 'Solicitação indisponível.');
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
                'expires_at_epoch' => $expiresAt, 'ciphertext' => $this->encrypter->encrypt(json_encode(['token' => $plain, 'recipient' => $user->email], JSON_THROW_ON_ERROR), false),
                'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $outboxId;
        });
    }
}
