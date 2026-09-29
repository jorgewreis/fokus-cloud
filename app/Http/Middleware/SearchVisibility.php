<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SearchVisibility
{
    public function handle(Request $request, Closure $next)
    {
        // Restrict canonical redirects to public GET/HEAD pages; preserve API and account flows.
        $publicPaths = ['/', 'produtos', 'produtos/fokus-styles', 'produtos/fokus-law', 'produtos/fokus-law/planos', 'robots.txt', 'sitemap.xml'];
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            if ($request->getHost() === 'fokuscloud.com.br' && in_array($request->path(), $publicPaths, true)) {
                return redirect()->away('https://www.fokuscloud.com.br'.$request->getRequestUri(), 301);
            }
            if ($request->getHost() === 'styles.fokuscloud.com.br' && $request->is('produtos', 'produtos/*')) {
                return redirect()->away('https://www.fokuscloud.com.br'.$request->getRequestUri(), 301);
            }
        }

        $response = $next($request);
        if ($request->is('api/*', 'portal', 'portal/*', 'backoffice', 'backoffice/*', 'auth/*', 'acesso', 'cadastro', 'verificar-email', 'criar-senha', 'recuperar-senha', 'aceitar-vinculo', 'aceitar-transferencia', 'up')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
