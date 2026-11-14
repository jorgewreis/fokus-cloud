<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Experimental token mutation primitive; not a complete login transaction. */
final class PrincipalSecurityToken
{
    /** The audit callback must write only to the same DB connection. */
    public function verifyEmail(string $plain, callable $recordEmailChange): array
    {
        return $this->consume($plain, 'email_verification', function (object $token, object $lockedUser) use ($recordEmailChange): array {
            $this->ensureEligibleUser($lockedUser);
            $payload = json_decode($token->payload ?? '', true) ?: [];
            $changes = ['email_verified_at' => now(), 'status' => 'ativa'];
            if (! empty($payload['new_email'])) {
                abort_if(DB::table('users')->where('email', $payload['new_email'])->where('id', '!=', $token->user_id)->exists(), 422, 'Este e-mail já está vinculado a outra conta.');
                $changes['email'] = $payload['new_email'];
            }
            $saved = User::findOrFail($token->user_id)->forceFill($changes)->save();
            abort_unless($saved, 422, 'A conta não pôde ser atualizada.');
            if (! empty($payload['new_email'])) {
                $recordEmailChange($token->user_id);
            }
            if (! empty($payload['company_id'])) {
                DB::table('companies')->where('id', $payload['company_id'])->where('created_by', $token->user_id)
                    ->where('status', 'pendente')->update(['status' => 'ativa', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
            }

            return ['user_id' => $token->user_id, 'payload' => $payload];
        });
    }

    /** The caller must handle session transition; this does not authenticate. */
    public function acceptMembership(string $plain): array
    {
        if ($plain === '') {
            throw new InvalidArgumentException('Invalid membership token operation.');
        }
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Membership acceptance requires a top-level transaction.');
        }
        $hash = hash('sha256', $plain);
        $hint = DB::table('security_tokens')->where('token_hash', $hash)->where('purpose', 'membership_acceptance')
            ->whereNull('used_at')->where('expires_at', '>', now())->first(['user_id', 'payload']);
        $hintPayload = $hint ? json_decode($hint->payload ?? '', true) : null;
        $membershipId = is_array($hintPayload) ? ($hintPayload['membership_id'] ?? null) : null;
        $invitationId = is_array($hintPayload) ? ($hintPayload['invitation_id'] ?? null) : null;
        abort_unless($hint && is_string($hint->user_id) && is_string($membershipId) && $membershipId !== ''
            && is_string($invitationId) && $invitationId !== '', 422, 'Convite inválido.');
        $companyId = DB::table('company_memberships')->where('id', $membershipId)->where('user_id', $hint->user_id)->value('company_id');
        abort_unless(is_string($companyId) && $companyId !== '', 422, 'Convite inválido.');

        return DB::transaction(function () use ($hash, $hint, $membershipId, $invitationId, $companyId): array {
            $company = DB::table('companies')->where('id', $companyId)->where('status', 'ativa')
                ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
            abort_unless($company, 422, 'Este convite não pode mais ser aceito.');
            $lockedUser = DB::table('users')->where('id', $hint->user_id)->lockForUpdate()->first(['id', 'status', 'locked_until']);
            abort_unless($lockedUser, 422, 'A conta não está disponível.');
            $this->ensureEligibleUser($lockedUser);
            $token = DB::table('security_tokens')->where('token_hash', $hash)->where('purpose', 'membership_acceptance')
                ->whereNull('used_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($token && $token->user_id === $hint->user_id, 422, 'Convite inválido.');
            $payload = json_decode($token->payload ?? '', true) ?: [];
            abort_unless(($payload['membership_id'] ?? null) === $membershipId && ($payload['invitation_id'] ?? null) === $invitationId, 422, 'Convite inválido.');
            $membership = DB::table('company_memberships')->where('id', $membershipId)->where('user_id', $token->user_id)
                ->where('company_id', $companyId)->where('status', 'pendente')->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($membership, 422, 'Este convite não pode mais ser aceito.');
            $invitation = DB::table('company_invitations')->where('id', $invitationId)->where('membership_id', $membershipId)->where('company_id', $company->id)
                ->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->first(['id']);
            abort_unless($invitation, 422, 'Este convite não pode mais ser aceito.');
            $used = DB::table('security_tokens')->where('id', $token->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
            abort_unless($used === 1, 422, 'Convite inválido.');
            $changed = DB::table('company_memberships')->where('id', $membershipId)->where('user_id', $token->user_id)->where('status', 'pendente')
                ->update(['status' => 'ativo', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
            abort_unless($changed === 1, 422, 'Este convite não pode mais ser aceito.');
            DB::table('law_unit_memberships')->where('company_membership_id', $membershipId)->where('status', 'pendente')
                ->update(['status' => 'ativo', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
            DB::table('company_invitations')->where('id', $invitation->id)->whereNull('accepted_at')
                ->update(['accepted_at' => now(), 'updated_at' => now()]);

            return ['user_id' => $token->user_id, 'company_id' => DB::table('company_memberships')->where('id', $membershipId)->value('company_id')];
        });
    }

    private function ensureEligibleUser(object $user): void
    {
        abort_unless(in_array($user->status, ['ativa', 'pendente'], true)
            && ($user->locked_until === null || Carbon::parse($user->locked_until)->isPast()), 422, 'A conta não está disponível.');
    }

    /** The callback may only perform local writes on the business DB connection. */
    public function consume(string $plain, string $purpose, callable $mutation): mixed
    {
        if ($plain === '' || ! in_array($purpose, ['email_verification', 'membership_acceptance'], true)) {
            throw new InvalidArgumentException('Invalid token operation.');
        }
        $hash = hash('sha256', $plain);
        $hint = DB::table('security_tokens')->where('token_hash', $hash)->where('purpose', $purpose)
            ->whereNull('used_at')->where('expires_at', '>', now())->value('user_id');
        abort_unless(is_string($hint) && $hint !== '', 422, 'Link inválido ou expirado.');

        return (new WebPrincipalGeneration)->withLockedPrincipal($hint, function (int $generation, object $user) use ($hash, $purpose, $hint, $mutation): mixed {
            $token = DB::table('security_tokens')->where('token_hash', $hash)->where('purpose', $purpose)
                ->whereNull('used_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($token && $token->user_id === $hint, 422, 'Link inválido ou expirado.');
            $used = DB::table('security_tokens')->where('id', $token->id)->whereNull('used_at')
                ->update(['used_at' => now(), 'updated_at' => now()]);
            abort_unless($used === 1, 422, 'Link inválido ou expirado.');

            return $mutation($token, $user, $generation);
        });
    }
}
