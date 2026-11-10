<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        $companyId = $request->session()->get('active_company_id');
        if (! $companyId) {
            return response()->json(['message' => 'Selecione a empresa ativa para continuar.'], 409);
        }

        $membership = DB::table('company_memberships as membership')
            ->join('roles', 'roles.id', '=', 'membership.role_id')
            ->where('membership.company_id', $companyId)
            ->where('membership.user_id', $request->user()->id)
            ->where('membership.status', 'ativo')
            ->whereNull('membership.deleted_at')
            ->select('membership.*', 'roles.code as role')
            ->first();

        if (! $membership) {
            $request->session()->forget('active_company_id');
            return response()->json(['message' => 'Seu vínculo com a empresa não está ativo.'], 403);
        }

        $request->attributes->set('active_company_id', $companyId);
        $request->attributes->set('active_membership', $membership);

        return $next($request);
    }
}
