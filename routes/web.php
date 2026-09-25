<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\SearchDiscoveryController;

// These resources must reach Laravel: both sites share the same public directory.
Route::withoutMiddleware('web')->group(function () {
    Route::get('/robots.txt', [SearchDiscoveryController::class, 'robots']);
    Route::get('/sitemap.xml', [SearchDiscoveryController::class, 'sitemap']);
    Route::get('/sitemap-styles.xml', fn () => redirect()->away('https://styles.fokuscloud.com.br/sitemap.xml', 301));
});

Route::domain('styles.fokuscloud.com.br')->group(function () {
    Route::get('/', function () {
        return response()->file(public_path('styles/index.html'));
    });

    Route::get('/layout', function () {
        return response()->file(public_path('styles/docs/layout/index.html'));
    });

    Route::get('/forms', function () {
        return response()->file(public_path('styles/docs/forms/index.html'));
    });

    Route::get('/components', function () {
        return response()->file(public_path('styles/docs/components/index.html'));
    });

    Route::get('/helpers', function () {
        return response()->file(public_path('styles/docs/helpers/index.html'));
    });

    Route::get('/utilities', function () {
        return response()->file(public_path('styles/docs/utilities/index.html'));
    });
});

Route::get('/', function () {
    return response()->file(public_path('index.html'));
});

// These endpoints intentionally inherit the web group: session cookies and
// CSRF protection are required for every browser-originated request.
Route::prefix('api')->middleware(\App\Http\Middleware\RequireApiCsrfToken::class)->group(base_path('routes/api.php'));

Route::get('/api/csrf-token', fn () => response()->json(['token' => csrf_token()]));

Route::get('/acesso', fn () => redirect('/?acesso=cliente'));
Route::get('/portal', fn () => response()->file(public_path('portal/dashboard.html')));
Route::get('/portal/painel', fn () => response()->file(public_path('portal/dashboard.html')));
$serveFokusLawShell = function (string $page = 'overview', bool $redirectProfile = false, bool $adminOnly = false) {
    $user = Auth::guard('web')->user();
    if (! $user || $user->status !== 'ativa') {
        return redirect('/?acesso=cliente');
    }
    if (! $user->email_verified_at) {
        return redirect('/verificar-email');
    }

    $companyId = request()->session()->get('active_company_id');
    if (! $companyId) {
        return redirect('/portal/empresas');
    }

    $membership = DB::table('company_memberships as membership')
        ->join('companies as company', 'company.id', '=', 'membership.company_id')
        ->where('membership.company_id', $companyId)
        ->where('membership.user_id', $user->id)
        ->where('membership.status', 'ativo')
        ->whereNull('membership.deleted_at')
        ->where('company.status', 'ativa')
        ->whereNull('company.deleted_at')
        ->select('membership.id', 'membership.role_id')
        ->first();

    if (! $membership) {
        request()->session()->forget('active_company_id');
        return redirect('/portal/empresas');
    }

    if ($adminOnly) {
        $role = DB::table('roles')->where('id', $membership->role_id)->value('code');
        abort_unless($role === 'admin', 403, 'Apenas o administrador da empresa pode acessar esta página.');
    }

    if ($redirectProfile) {
        return redirect('/portal/fokus-law/perfil');
    }

    return response()->view('portal.fokus-law', ['initialPage' => $page]);
};
Route::get('/portal/fokus-law', fn () => $serveFokusLawShell());
Route::get('/portal/fokus-law/empresa', fn () => $serveFokusLawShell('company', false, true));
Route::get('/portal/fokus-law/perfil', fn () => $serveFokusLawShell('profile'));
Route::get('/portal/perfil', fn () => $serveFokusLawShell('profile', true));
Route::get('/cadastro', fn () => response()->file(public_path('auth/cadastro.html')));
Route::get('/verificar-email', fn () => response()->file(public_path('auth/verificar-email.html')));
Route::get('/criar-senha', fn () => response()->file(public_path('auth/criar-senha.html')));
Route::get('/recuperar-senha', fn () => response()->file(public_path('auth/recuperar-senha.html')));
Route::get('/aceitar-vinculo', fn () => response()->file(public_path('auth/aceitar-vinculo.html')));
Route::get('/aceitar-transferencia', fn () => response()->file(public_path('auth/aceitar-transferencia.html')));
Route::get('/portal/empresas', fn () => response()->file(public_path('portal/companies.html')));
Route::get('/portal/usuarios', fn () => response()->file(public_path('portal/users.html')));
Route::get('/portal/assinaturas', fn () => response()->file(public_path('portal/subscriptions.html')));
Route::get('/portal/transferir-administracao', fn () => response()->file(public_path('portal/admin-transfer.html')));
Route::get('/backoffice/ativar', fn () => response()->file(public_path('backoffice/ativar.html')));
Route::get('/backoffice/confirmar-email', fn () => response()->file(public_path('backoffice/confirmar-email.html')));
$serveBackofficeShell = function () {
    $admin = Auth::guard('platform')->user();

    if (! $admin || ! $admin->isAvailableForLogin() || ! $admin->hasPermission('platform.access')) {
        return redirect('/?acesso=administrativo');
    }

    return response()->file(public_path('backoffice/index.html'));
};
Route::get('/backoffice/index.html', $serveBackofficeShell);
Route::get('/backoffice/{page?}', $serveBackofficeShell)
    ->where('page', 'painel|empresas|produtos|modulos|modules|planos|catalogo|visao-geral-catalogo|assinaturas|vouchers|pagamentos|billing|seguranca|usuarios|interesses|product-interests|componentes');
Route::get('/produtos', fn () => response()->file(public_path('marketing/products/index.html')));
Route::get('/produtos/fokus-styles', fn () => response()->file(public_path('marketing/products/fokus-styles.html')));
Route::get('/produtos/fokus-law', fn () => response()->file(public_path('marketing/products/fokus-law.html')));
Route::get('/produtos/fokus-lead', fn () => response()->file(public_path('marketing/products/fokus-lead.html')));
Route::get('/privacidade', fn () => response()->file(public_path('marketing/privacy.html')));

// Development-server fallback. Production NGINX redirects these physical legacy paths before serving static files.
Route::permanentRedirect('/admin', '/acesso');
Route::permanentRedirect('/admin/painel', '/portal');
Route::permanentRedirect('/admin/perfil', '/portal/fokus-law/perfil');
Route::permanentRedirect('/auth/cadastro.html', '/cadastro');
Route::permanentRedirect('/auth/verificar-email.html', '/verificar-email');
Route::permanentRedirect('/auth/criar-senha.html', '/criar-senha');
Route::permanentRedirect('/auth/recuperar-senha.html', '/recuperar-senha');
Route::permanentRedirect('/auth/aceitar-vinculo.html', '/aceitar-vinculo');
Route::permanentRedirect('/auth/aceitar-transferencia.html', '/aceitar-transferencia');
Route::permanentRedirect('/admin/empresas', '/portal/empresas');
Route::permanentRedirect('/admin/usuarios', '/portal/usuarios');
Route::permanentRedirect('/admin/assinaturas', '/portal/assinaturas');
Route::permanentRedirect('/admin/transferir-administracao', '/portal/transferir-administracao');
Route::permanentRedirect('/admin/empresas.html', '/portal/empresas');
Route::permanentRedirect('/admin/usuarios.html', '/portal/usuarios');
Route::permanentRedirect('/admin/assinaturas.html', '/portal/assinaturas');
