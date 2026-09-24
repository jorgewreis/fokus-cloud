<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExpiredData extends Command
{
    protected $signature = 'fokus:prune-expired-data';
    protected $description = 'Remove dados cuja retenção documentada terminou.';

    public function handle(): int
    {
        $tokens = DB::table('security_tokens')->where('expires_at', '<', now()->subDays(90))->delete();
        $audits = DB::table('audit_events')->where('expires_at', '<=', now())->delete();
        $platformAudits = DB::table('platform_audit_events')->where('expires_at', '<=', now())->delete();
        $platformChallenges = DB::table('platform_login_challenges')->where('expires_at', '<', now())->delete();
        $platformAttempts = DB::table('platform_login_attempts')->where('created_at', '<', now()->subDays(180))->delete();
        $platformInvitations = DB::table('platform_admin_invitations')->where('expires_at', '<', now()->subDays(90))->delete();
        $expiredInvitations = DB::table('company_invitations')->where('expires_at', '<', now()->subDays(90))->delete();
        $memberships = DB::table('company_memberships')->where('status', 'removido')->whereNotNull('deleted_at')->where('deleted_at', '<', now()->subDays(90))->delete();
        $this->info("Tokens removidos: {$tokens}; auditorias removidas: {$audits}; auditorias internas removidas: {$platformAudits}; desafios MFA removidos: {$platformChallenges}; tentativas internas removidas: {$platformAttempts}; convites internos removidos: {$platformInvitations}; convites removidos: {$expiredInvitations}; vínculos removidos: {$memberships}.");
        return self::SUCCESS;
    }
}
