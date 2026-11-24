<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SearchVisibility
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $redirect = $this->canonicalRedirect($request);
            if ($redirect !== null) {
                return $redirect;
            }
        }

        $response = $next($request);
        if ($request->is('api/*', 'portal', 'portal/*', 'backoffice', 'backoffice/*', 'auth/*', 'acesso', 'cadastro', 'verificar-email', 'criar-senha', 'recuperar-senha', 'aceitar-vinculo', 'aceitar-transferencia', 'up')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    private function canonicalRedirect(Request $request): ?RedirectResponse
    {
        $host = $request->getHost();
        $styles = $host === 'styles.fokuscloud.com.br';
        $apex = $host === 'fokuscloud.com.br';
        $www = $host === 'www.fokuscloud.com.br';
        if (! $styles && ! $apex && ! $www) {
            return null;
        }

        $forwarded = strtolower(trim(explode(',', (string) $request->headers->get('X-Forwarded-Proto', ''))[0]));
        $secure = $request->isSecure() || $forwarded === 'https';
        $wwwUrl = 'https://www.fokuscloud.com.br'.$request->getRequestUri();

        // Product pages live on the institutional host even when requested from Styles.
        if ($styles && $request->is('produtos', 'produtos/*')) {
            return redirect()->away($wwwUrl, 301);
        }

        if (! $secure) {
            $target = $styles
                ? 'https://styles.fokuscloud.com.br'.$request->getRequestUri()
                : $wwwUrl;

            return redirect()->away($target, 301);
        }

        // HTTPS on the apex moves only the public catalog. API and account clients stay put.
        $publicPaths = ['/', 'produtos', 'produtos/fokus-styles', 'produtos/fokus-law', 'produtos/fokus-law/planos', 'produtos/fokus-law/sob-medida', 'privacidade', 'termos-de-uso', 'robots.txt', 'sitemap.xml'];
        if ($apex && in_array($request->path(), $publicPaths, true)) {
            return redirect()->away($wwwUrl, 301);
        }

        return null;
    }
}
