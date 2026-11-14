<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Experimental token mutation primitive; not a complete login transaction. */
final class PrincipalSecurityToken
{
    /** The audit callback must write only to the same DB connection. */
    public function verifyEmail(string $plain, callable $recordEmailChange): array
    {
        return $this->consume($plain, 'email_verification', function (object $token) use ($recordEmailChange): array {
            $payload = json_decode($token->payload ?? '', true) ?: [];
            $changes = ['email_verified_at' => now(), 'status' => 'ativa'];
            if (! empty($payload['new_email'])) {
                abort_if(DB::table('users')->where('email', $payload['new_email'])->where('id', '!=', $token->user_id)->exists(), 422, 'Este e-mail já está vinculado a outra conta.');
                $changes['email'] = $payload['new_email'];
            }
            DB::table('users')->where('id', $token->user_id)->update($changes);
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
        return $this->consume($plain, 'membership_acceptance', function (object $token): array {
            $payload = json_decode($token->payload ?? '', true) ?: [];
            $membershipId = $payload['membership_id'] ?? null;
            abort_unless(is_string($membershipId) && $membershipId !== '', 422, 'Convite inválido.');
            $changed = DB::table('company_memberships')->where('id', $membershipId)->where('user_id', $token->user_id)->where('status', 'pendente')
                ->update(['status' => 'ativo', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
            abort_unless($changed === 1, 422, 'Este convite não pode mais ser aceito.');
            DB::table('law_unit_memberships')->where('company_membership_id', $membershipId)->where('status', 'pendente')
                ->update(['status' => 'ativo', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
            DB::table('company_invitations')->where('membership_id', $membershipId)->whereNull('accepted_at')
                ->update(['accepted_at' => now(), 'updated_at' => now()]);

            return ['user_id' => $token->user_id, 'company_id' => DB::table('company_memberships')->where('id', $membershipId)->value('company_id')];
        });
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
