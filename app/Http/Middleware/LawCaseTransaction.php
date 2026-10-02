<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LawCaseTransaction
{
    public function handle(Request $request, Closure $next)
    {
        // Read requests and remote consultations manage their own transactions.
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || $request->is('api/law/cases') || $request->is('api/law/cases/*/datajud')) return $next($request);
        return DB::transaction(fn () => $next($request));
    }
}
