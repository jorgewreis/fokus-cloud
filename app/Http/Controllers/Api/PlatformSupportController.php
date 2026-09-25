<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlatformAudit;
use App\Services\PrefixedUlid;
use App\Services\SupportSessionSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PlatformSupportController extends Controller
{
    public function lawContext()
    {
        $subscriptions = DB::table('subscriptions as subscription')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->join('companies as company', 'company.id', '=', 'subscription.company_id')
            ->whereIn('product.code', ['law', 'fokus-law'])->whereNull('company.deleted_at')
            ->select('subscription.id', 'subscription.company_id', 'subscription.status', 'subscription.commercial_snapshot', 'company.legal_name')
            ->orderBy('company.legal_name')->get()
            ->map(function (object $subscription): array {
                $snapshot = json_decode((string) $subscription->commercial_snapshot, true) ?: [];
                $users = DB::table('company_memberships as membership')
                    ->join('users as user', 'user.id', '=', 'membership.user_id')
                    ->join('roles as role', 'role.id', '=', 'membership.role_id')
                    ->where('membership.company_id', $subscription->company_id)->where('membership.status', 'ativo')
                    ->whereNull('membership.deleted_at')->where('user.status', 'ativa')
                    ->select('membership.id as membership_id', 'user.name', 'user.email', 'role.name as role_name')
                    ->orderBy('user.name')->get()->map(fn (object $user): array => [
                        'membership_id' => $user->membership_id,
                        'label' => $user->role_name.' — '.$user->name.' ('.$user->email.')',
                    ])->values();

                return [
                    'id' => $subscription->id,
                    'label' => $subscription->legal_name.' — '.($snapshot['plan_name'] ?? 'Plano Fokus Law').' ('.ucfirst($subscription->status).')',
                    'status' => $subscription->status,
                    'users' => $users,
                ];
            })->values();

        return response()->json(['subscriptions' => $subscriptions]);
    }

    public function start(Request $request, PlatformAudit $audit, SupportSessionSecurity $security)
    {
        $data = $request->validate([
            'subscription_id' => ['required', 'string', 'size:30'],
            'membership_id' => ['required', 'string', 'size:30'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $target = DB::table('subscriptions as subscription')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->join('company_memberships as membership', function ($join) use ($data): void {
                $join->on('membership.company_id', '=', 'subscription.company_id')->where('membership.id', '=', $data['membership_id']);
            })
            ->join('companies as company', 'company.id', '=', 'subscription.company_id')
            ->join('users as user', 'user.id', '=', 'membership.user_id')
            ->where('subscription.id', $data['subscription_id'])->whereIn('product.code', ['law', 'fokus-law'])
            ->where('membership.status', 'ativo')->whereNull('membership.deleted_at')
            ->where('company.status', 'ativa')->whereNull('company.deleted_at')->where('user.status', 'ativa')
            ->select('subscription.id as subscription_id', 'subscription.company_id', 'membership.id as membership_id', 'membership.user_id', 'company.legal_name')
            ->first();
        abort_unless($target, 422, 'A assinatura e o usuário precisam pertencer à mesma empresa e estar disponíveis.');

        $security->end($request, 'Acesso anterior encerrado ao iniciar outra sessão de suporte.');
        $id = PrefixedUlid::make('SUP');
        DB::table('platform_support_sessions')->insert([
            'id' => $id, 'platform_admin_id' => Auth::guard('platform')->id(), 'company_id' => $target->company_id,
            'subscription_id' => $target->subscription_id, 'membership_id' => $target->membership_id,
            'target_user_id' => $target->user_id, 'reason' => app(\App\Services\AuditSanitizer::class)->sanitizeText((string) $data['reason']), 'started_at' => now(),
            'start_ip' => $request->ip(), 'start_user_agent' => app(\App\Services\AuditSanitizer::class)->sanitizeText((string) $request->userAgent()), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $request->session()->put(['support_session_id' => $id, 'active_company_id' => $target->company_id]);
        Auth::guard('web')->login(User::findOrFail($target->user_id));
        $request->session()->regenerate();
        $audit->record(Auth::guard('platform')->id(), 'backoffice.support_access_started', 'platform_support_session', $id, $target->company_id, $data['reason'], metadata: ['subscription_id' => $target->subscription_id, 'membership_id' => $target->membership_id, 'target_user_id' => $target->user_id], after: ['status' => 'active', 'subscription_id' => $target->subscription_id], request: $request);

        return response()->json(['redirect_to' => '/portal/fokus-law']);
    }

    public function exit(Request $request, SupportSessionSecurity $security)
    {
        abort_unless($security->end($request), 404, 'Não há acesso de suporte ativo.');

        return response()->json(['redirect_to' => '/backoffice/']);
    }
}
