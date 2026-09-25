<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordSecurity;
use App\Services\PrefixedUlid;
use App\Support\BrazilianDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function lawContext(Request $request)
    {
        $email = Str::lower(trim((string) $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ])['email']));

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        abort_unless($user, 404, 'Usuário não encontrado.');
        $userPayload = ['name' => $user->name, 'email' => $user->email];

        if ($user->status !== 'ativa') {
            return response()->json(['user' => $userPayload, 'systems' => [], 'message' => 'A conta deste usuário não está ativa.']);
        }

        $hasActiveCompany = DB::table('company_memberships as membership')
            ->join('companies as company', 'company.id', '=', 'membership.company_id')
            ->where('membership.user_id', $user->id)->where('membership.status', 'ativo')
            ->whereNull('membership.deleted_at')->where('company.status', 'ativa')->whereNull('company.deleted_at')
            ->exists();

        if (! $hasActiveCompany) {
            return response()->json(['user' => $userPayload, 'systems' => [], 'message' => 'Este usuário não possui vínculo ativo com uma empresa.']);
        }

        $lawSystems = DB::table('company_memberships as membership')
            ->join('companies as company', 'company.id', '=', 'membership.company_id')
            ->join('roles as role', 'role.id', '=', 'membership.role_id')
            ->join('subscriptions as subscription', 'subscription.company_id', '=', 'company.id')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->leftJoin('subscription_items as item', 'item.subscription_id', '=', 'subscription.id')
            ->leftJoin('modules as module', 'module.id', '=', 'item.module_id')
            ->leftJoin('module_segments as module_segment', 'module_segment.module_id', '=', 'module.id')
            ->where('membership.user_id', $user->id)
            ->where('membership.status', 'ativo')
            ->whereNull('membership.deleted_at')
            ->where('company.status', 'ativa')
            ->whereNull('company.deleted_at')
            ->where('subscription.status', 'ativa')
            ->whereIn('product.code', ['law', 'fokus-law'])
            ->select('company.id as company_id', 'company.legal_name as company_name', 'subscription.public_name as subscription_public_name', 'product.name as product_name', 'role.code as profile_code', 'role.name as profile_name', 'module_segment.segment_code')
            ->orderBy('company.legal_name')
            ->get();

        if ($lawSystems->isEmpty()) {
            return response()->json(['user' => $userPayload, 'systems' => [], 'message' => 'Não existe nenhuma assinatura ativa do Fokus Law vinculada a este usuário.']);
        }

        $systems = $lawSystems->groupBy('company_id')->map(function ($rows): array {
            $segment = $rows->pluck('segment_code')->filter()->first() ?: 'juridico';
            $segmentLabel = match ($segment) {
                'advocacia' => 'Advocacia',
                'setor_publico' => 'Setor Público',
                default => 'Jurídico',
            };

            return [
                'value' => (string) $rows->first()->company_id,
                'label' => (($rows->first()->subscription_public_name ?: $rows->first()->company_name).' — '.$rows->first()->product_name.' · '.$segmentLabel),
                'profiles' => $rows->map(fn (object $row): array => [
                    'value' => (string) $row->profile_code,
                    'label' => (string) $row->profile_name,
                ])->unique('value')->values()->all(),
            ];
        })->values()->all();

        return response()->json(['user' => $userPayload, 'systems' => $systems]);
    }

    public function registerCompany(Request $request, PasswordSecurity $passwordSecurity)
    {
        $data = $this->companyRegistrationData($request, true);
        $cpf = BrazilianDocuments::digits($data['cpf']);
        $document = BrazilianDocuments::digits($data['document_number']);
        $this->validateDocuments($data['document_type'], $document, $cpf);
        $passwordSecurity->validate($data['password']);

        $this->assertCompanyIsAvailable($data['document_type'], $document);
        if (User::where('cpf', $cpf)->exists()) {
            return response()->json([
                'message' => 'Este CPF já possui conta. Entre para vinculá-la à nova empresa.',
                'requires_login' => true,
            ], 409);
        }
        if (User::where('email', Str::lower($data['email']))->exists()) {
            throw ValidationException::withMessages(['email' => 'Este e-mail já está vinculado a outra conta.']);
        }

        $user = DB::transaction(function () use ($data, $cpf, $document) {
            $user = User::create([
                'id' => PrefixedUlid::make('USR'),
                'name' => $data['name'],
                'cpf' => $cpf,
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
                'status' => 'pendente',
            ]);
            $companyId = $this->createCompanyFor($user, $data, $document);
            $this->recordLegalAcceptances($user, $data);
            $user->setAttribute('new_company_id', $companyId);
            return $user;
        });

        $companyId = $user->getAttribute('new_company_id');
        $this->sendToken($user, 'email_verification', '/verificar-email', [
            'company_id' => $companyId,
            'return_to' => $data['return_to'] ?? '/portal',
        ]);
        $this->authenticateIntoSession($request, $user, $companyId);

        return response()->json([
            'message' => 'Cadastro criado. Confirme seu e-mail antes de escolher a assinatura.',
            'company_id' => $companyId,
        ], 201);
    }

    public function registerCompanyForCurrentUser(Request $request)
    {
        $data = $this->companyRegistrationData($request, false);
        $user = $request->user();
        $document = BrazilianDocuments::digits($data['document_number']);
        $this->validateCompanyDocument($data['document_type'], $document);
        $this->assertCompanyIsAvailable($data['document_type'], $document);

        $companyId = DB::transaction(function () use ($user, $data, $document) {
            $companyId = $this->createCompanyFor($user, $data, $document);
            $this->recordLegalAcceptances($user, $data);
            return $companyId;
        });

        if (! $user->email_verified_at) {
            $this->sendToken($user, 'email_verification', '/verificar-email', [
                'company_id' => $companyId,
                'return_to' => $data['return_to'] ?? '/portal',
            ]);
        }
        $request->session()->put('active_company_id', $companyId);
        $request->session()->save();

        return response()->json([
            'message' => $user->email_verified_at
                ? 'Empresa criada e vinculada à sua conta.'
                : 'Empresa criada. Confirme seu e-mail para continuar.',
            'company_id' => $companyId,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'document' => ['nullable', 'string', 'required_without:cpf'],
            'cpf' => ['nullable', 'string', 'required_without:document'],
            'password' => ['required', 'string'],
        ]);
        $document = BrazilianDocuments::digits($data['document'] ?? $data['cpf'] ?? '');
        $companyId = null;

        if (BrazilianDocuments::cpf($document)) {
            $user = User::where('cpf', $document)->first();
        } elseif (BrazilianDocuments::cnpj($document)) {
            $companyLogin = DB::table('companies as company')
                ->join('company_memberships as membership', 'membership.company_id', '=', 'company.id')
                ->join('roles as role', 'role.id', '=', 'membership.role_id')
                ->where('company.document_type', 'cnpj')
                ->where('company.document_number', $document)
                ->where('company.status', 'ativa')
                ->whereNull('company.deleted_at')
                ->where('membership.status', 'ativo')
                ->whereNull('membership.deleted_at')
                ->where('role.code', 'admin')
                ->select('membership.user_id', 'company.id as company_id')
                ->first();
            $user = $companyLogin ? User::find($companyLogin->user_id) : null;
            $companyId = $companyLogin?->company_id;
        } else {
            throw ValidationException::withMessages(['document' => 'Informe um CPF ou CNPJ válido.']);
        }

        if (! $user || $user->status === 'desativada' || ($user->locked_until && $user->locked_until->isFuture()) || ! Hash::check($data['password'], $user->password)) {
            if ($user) {
                $this->recordFailedLogin($user);
            }
            return response()->json(['message' => 'CPF/CNPJ ou senha inválidos.'], 422);
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'login_attempt_window_started_at' => null,
            'locked_until' => null,
            'status' => $user->status === 'bloqueada' ? 'ativa' : $user->status,
        ])->save();
        $companies = $this->companiesFor($user);
        $companyId ??= count($companies) === 1 ? $companies[0]->id : null;
        $this->authenticateIntoSession($request, $user, $companyId);

        return response()->json(['user' => $this->userPayload($user), 'companies' => $companies, 'active_company_id' => $companyId]);
    }

    public function lawLogin(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string'],
            'company_id' => ['required', 'string', 'max:40'],
            'profile' => ['required', 'string', 'max:80'],
        ]);

        $user = User::whereRaw('LOWER(email) = ?', [Str::lower(trim($data['email']))])->first();
        if (! $user || $user->status !== 'ativa' || ($user->locked_until && $user->locked_until->isFuture()) || ! Hash::check($data['password'], $user->password)) {
            if ($user) {
                $this->recordFailedLogin($user);
            }

            return response()->json(['message' => 'E-mail ou senha inválidos.'], 422);
        }

        $membership = DB::table('company_memberships as membership')
            ->join('companies as company', 'company.id', '=', 'membership.company_id')
            ->join('roles as role', 'role.id', '=', 'membership.role_id')
            ->where('membership.company_id', $data['company_id'])
            ->where('membership.user_id', $user->id)
            ->where('membership.status', 'ativo')
            ->whereNull('membership.deleted_at')
            ->where('company.status', 'ativa')
            ->whereNull('company.deleted_at')
            ->where('role.code', $data['profile'])
            ->exists();
        $hasLawSubscription = DB::table('subscriptions as subscription')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('subscription.company_id', $data['company_id'])
            ->where('subscription.status', 'ativa')
            ->whereIn('product.code', ['law', 'fokus-law'])
            ->exists();

        if (! $membership || ! $hasLawSubscription) {
            return response()->json(['message' => 'A empresa, o perfil ou a assinatura selecionados não estão disponíveis para esta conta.'], 403);
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'login_attempt_window_started_at' => null,
            'locked_until' => null,
        ])->save();
        $this->authenticateIntoSession($request, $user, $data['company_id']);

        $redirectTo = $user->email_verified_at
            ? '/portal/fokus-law'
            : '/verificar-email?return_to='.rawurlencode('/portal/fokus-law');

        return response()->json([
            'user' => $this->userPayload($user),
            'active_company_id' => $data['company_id'],
            'redirect_to' => $redirectTo,
        ]);
    }

    public function logout(Request $request, \App\Services\SupportSessionSecurity $supportSecurity)
    {
        $supportSecurity->end($request, 'Sessão encerrada ao sair do portal.');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->noContent();
    }

    public function me(Request $request)
    {
        $user = Auth::guard('web')->user();
        abort_unless($user, 401, 'Acesso de usuário não autenticado.');
        $supportId = $request->session()->get('support_session_id');
        $supportMode = $supportId ? DB::table('platform_support_sessions as support')
            ->join('subscriptions as subscription', 'subscription.id', '=', 'support.subscription_id')
            ->join('companies as company', 'company.id', '=', 'support.company_id')
            ->where('support.id', $supportId)->whereNull('support.ended_at')
            ->select('support.id', 'support.reason', 'subscription.status as subscription_status', 'company.legal_name as company_name')
            ->first() : null;

        $profile = $this->userPayload($user);
        if (! $supportMode) {
            $profile['phone'] = $user->phone;
        }

        return response()->json([
            'user' => $profile,
            'companies' => $this->companiesFor($user),
            'active_company_id' => $request->session()->get('active_company_id'),
            'support_mode' => $supportMode ? ['active' => true, 'company' => $supportMode->company_name, 'subscription_status' => $supportMode->subscription_status, 'reason' => $supportMode->reason] : null,
        ]);
    }

    public function updateProfile(Request $request, PasswordSecurity $passwordSecurity)
    {
        $supportId = $request->session()->get('support_session_id');
        abort_if($supportId && DB::table('platform_support_sessions')->where('id', $supportId)->whereNull('ended_at')->exists(), 403, 'Encerre o acesso de suporte antes de alterar seu perfil.');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'password' => ['sometimes', 'required', 'string', 'min:12', 'confirmed'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'current_password' => ['nullable', 'string'],
        ]);
        abort_if(empty($data), 422, 'Informe ao menos um dado para alteração.');
        $user = $request->user();
        $changes = [];
        $requestedEmail = isset($data['email']) ? Str::lower(trim($data['email'])) : null;
        $emailChangeRequested = $requestedEmail !== null && $requestedEmail !== Str::lower($user->email);

        if ((isset($data['password']) || $emailChangeRequested) && ! Hash::check($data['current_password'] ?? '', $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Informe sua senha atual para alterar e-mail ou senha.']);
        }

        if (isset($data['name'])) {
            $changes['name'] = $data['name'];
        }
        if (array_key_exists('phone', $data)) {
            $digits = preg_replace('/\\D+/', '', (string) ($data['phone'] ?? ''));
            if ($digits !== '' && ! preg_match('/^(?:1[1-9]|[2-9][1-9])(?:[2-5][0-9]{7}|9[0-9]{8})$/', $digits)) {
                throw ValidationException::withMessages(['phone' => 'Informe um telefone brasileiro válido com DDD.']);
            }
            $changes['phone'] = $digits === '' ? null : $digits;
        }
        if (isset($data['password'])) {
            $passwordSecurity->validate($data['password']);
            $changes['password'] = $data['password'];
        }
        if ($emailChangeRequested) {
            $email = $requestedEmail;
            abort_if(User::where('email', $email)->where('id', '!=', $user->id)->exists(), 422, 'Este e-mail já está vinculado a outra conta.');
            $this->sendToken($user, 'email_verification', '/verificar-email', ['new_email' => $email], $email);
            Mail::raw('Foi solicitada uma alteração do e-mail da sua conta Fokus Cloud.', fn ($mail) => $mail->to($user->email)->subject('Fokus Cloud: solicitação de alteração de e-mail'));
            app(\App\Services\AuditRecorder::class)->platform(null, 'customer.email_change.requested', 'user', $user->id, metadata: ['fields' => ['email']], after: ['requested' => ['email']], request: $request, actorType: 'customer');
        }
        if ($changes) {
            $changedFields = array_values(array_filter(array_keys($changes), fn ($field) => $field !== 'password'));
            $user->forceFill($changes)->save();
            if (isset($changes['password'])) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $request->session()->regenerate();
            }
            app(\App\Services\AuditRecorder::class)->platform(null, 'customer.profile.updated', 'user', $user->id, metadata: ['fields' => [...$changedFields, ...(isset($changes['password']) ? ['password'] : [])]], before: ['fields' => $changedFields], after: ['fields' => [...$changedFields, ...(isset($changes['password']) ? ['password'] : [])]], request: $request, actorType: 'customer');
        }

        return response()->json(['message' => $emailChangeRequested ? 'Enviamos um link ao novo endereço. O e-mail atual continua ativo até a confirmação.' : 'Dados atualizados.', 'user' => [...$this->userPayload($user), 'phone' => $user->phone]]);
    }

    public function selectCompany(Request $request)
    {
        $data = $request->validate(['company_id' => ['required', 'string', 'size:30']]);
        $membership = DB::table('company_memberships')->where('company_id', $data['company_id'])
            ->where('user_id', $request->user()->id)->where('status', 'ativo')->whereNull('deleted_at')->first();
        abort_unless($membership, 403, 'Sem acesso a esta empresa.');
        $request->session()->put('active_company_id', $data['company_id']);
        $request->session()->save();
        return response()->json(['active_company_id' => $data['company_id']]);
    }

    public function verifyEmail(Request $request)
    {
        $token = $this->consumeToken($request->validate(['token' => ['required', 'string']])['token'], 'email_verification');
        $user = User::findOrFail($token->user_id);
        $payload = $token->payload ? json_decode($token->payload, true) : [];
        $changes = ['email_verified_at' => now(), 'status' => 'ativa'];
        if (! empty($payload['new_email'])) {
            abort_if(User::where('email', $payload['new_email'])->where('id', '!=', $user->id)->exists(), 422, 'Este e-mail já está vinculado a outra conta.');
            $changes['email'] = $payload['new_email'];
        }
        $user->forceFill($changes)->save();
        if (! empty($payload['new_email'])) {
            app(\App\Services\AuditRecorder::class)->platform(null, 'customer.email_change.confirmed', 'user', $user->id, metadata: ['fields' => ['email']], after: ['fields' => ['email']], request: $request, actorType: 'customer');
        }
        if (! empty($payload['company_id'])) {
            DB::table('companies')->where('id', $payload['company_id'])->where('created_by', $user->id)
                ->where('status', 'pendente')->update(['status' => 'ativa', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
        }
        $companyId = ! empty($payload['company_id']) ? $payload['company_id'] : $this->firstCompanyId($user);
        $this->authenticateIntoSession($request, $user, $companyId);
        $returnTo = data_get($payload, 'return_to', '/portal');
        if (! in_array($returnTo, ['/portal', '/portal/fokus-law', '/assinaturas/fokus-law', '/assinaturas/fokus-lead'], true)) {
            $returnTo = '/portal';
        }
        return response()->json(['message' => 'E-mail confirmado com sucesso.', 'return_to' => $returnTo]);
    }

    public function resendVerification(Request $request)
    {
        $returnTo = $request->validate([
            'return_to' => ['nullable', Rule::in(['/portal', '/portal/fokus-law'])],
        ])['return_to'] ?? '/portal';
        $this->sendToken($request->user(), 'email_verification', '/verificar-email', [
            'company_id' => $request->session()->get('active_company_id'),
            'return_to' => $returnTo,
        ]);
        return response()->json(['message' => 'Enviamos um novo link de confirmação.']);
    }

    public function requestPasswordReset(Request $request)
    {
        $cpf = BrazilianDocuments::digits($request->validate(['cpf' => ['required', 'string']])['cpf']);
        if (BrazilianDocuments::cpf($cpf) && ($user = User::where('cpf', $cpf)->first()) && $user->email_verified_at) {
            $this->sendToken($user, 'password_reset', '/criar-senha');
        }
        return response()->json(['message' => 'Se houver uma conta elegível, enviamos as instruções para o e-mail confirmado.']);
    }

    public function setPassword(Request $request, PasswordSecurity $passwordSecurity)
    {
        $data = $request->validate(['token' => ['required', 'string'], 'password' => ['required', 'string', 'min:12', 'confirmed']]);
        $passwordSecurity->validate($data['password']);
        $token = $this->consumeToken($data['token'], ['password_reset', 'password_creation']);
        $user = User::findOrFail($token->user_id);
        $user->forceFill(['password' => $data['password'], 'status' => 'ativa', 'email_verified_at' => $user->email_verified_at ?: now()])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        if (Auth::guard('web')->id() === $user->id) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        $payload = $token->payload ? json_decode($token->payload, true) : [];
        if (! empty($payload['membership_id'])) {
            DB::table('company_memberships')->where('id', $payload['membership_id'])->where('user_id', $user->id)->where('status', 'pendente')
                ->update(['status' => 'ativo', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
            DB::table('company_invitations')->where('membership_id', $payload['membership_id'])->whereNull('accepted_at')->update(['accepted_at' => now(), 'updated_at' => now()]);
        }
        return response()->json(['message' => 'Senha criada com sucesso. Agora você já pode entrar.']);
    }

    public function acceptMembership(Request $request)
    {
        $token = $this->consumeToken($request->validate(['token' => ['required', 'string']])['token'], 'membership_acceptance');
        $payload = json_decode($token->payload, true) ?: [];
        $membershipId = $payload['membership_id'] ?? null;
        abort_unless($membershipId, 422, 'Convite inválido.');
        DB::transaction(function () use ($membershipId, $token) {
            $changed = DB::table('company_memberships')->where('id', $membershipId)->where('user_id', $token->user_id)->where('status', 'pendente')
                ->update(['status' => 'ativo', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
            abort_unless($changed, 422, 'Este convite não pode mais ser aceito.');
            DB::table('company_invitations')->where('membership_id', $membershipId)->whereNull('accepted_at')->update(['accepted_at' => now(), 'updated_at' => now()]);
        });
        $user = User::findOrFail($token->user_id);
        $companyId = DB::table('company_memberships')->where('id', $membershipId)->value('company_id');
        $this->authenticateIntoSession($request, $user, $companyId);
        return response()->json(['message' => 'Vínculo aceito. A empresa está disponível na sua conta.', 'return_to' => '/portal']);
    }

    public function acceptAdminTransfer(Request $request)
    {
        $token = $this->consumeToken($request->validate(['token' => ['required', 'string']])['token'], 'admin_transfer');
        $payload = json_decode($token->payload, true) ?: [];
        foreach (['company_id', 'from_membership_id', 'to_membership_id'] as $key) {
            abort_unless(isset($payload[$key]), 422, 'Transferência inválida.');
        }
        DB::transaction(function () use ($payload, $token) {
            $target = DB::table('company_memberships')->where('id', $payload['to_membership_id'])->where('company_id', $payload['company_id'])
                ->where('user_id', $token->user_id)->where('status', 'ativo')->lockForUpdate()->first();
            $from = DB::table('company_memberships')->where('id', $payload['from_membership_id'])->where('company_id', $payload['company_id'])
                ->whereNotNull('active_admin_company_id')->lockForUpdate()->first();
            abort_unless($target && $from, 422, 'A transferência não pode mais ser concluída.');
            DB::table('company_memberships')->where('id', $from->id)->update([
                'active_admin_company_id' => null,
                'status' => ! empty($payload['keep_previous_access']) ? 'ativo' : 'removido',
                'deleted_at' => ! empty($payload['keep_previous_access']) ? null : now(),
                'deleted_by' => ! empty($payload['keep_previous_access']) ? null : $token->user_id,
                'updated_by' => $token->user_id,
                'updated_at' => now(),
                'version' => $from->version + 1,
            ]);
            DB::table('company_memberships')->where('id', $target->id)->update([
                'role_id' => DB::table('roles')->where('code', 'admin')->value('id'),
                'active_admin_company_id' => $payload['company_id'],
                'updated_by' => $token->user_id,
                'updated_at' => now(),
                'version' => $target->version + 1,
            ]);
            $this->audit($payload['company_id'], $token->user_id, 'company_membership', $target->id, 'update', ['previous_membership_id' => $from->id], ['new_admin_membership_id' => $target->id, 'previous_access_kept' => (bool) ($payload['keep_previous_access'] ?? false)]);
        });
        $previousAdmin = DB::table('company_memberships as membership')->join('users', 'users.id', '=', 'membership.user_id')->where('membership.id', $payload['from_membership_id'])->value('users.email');
        $newAdmin = User::findOrFail($token->user_id);
        Mail::raw('A transferência de administração da empresa foi concluída.', fn ($mail) => $mail->to($newAdmin->email)->subject('Fokus Cloud: administração transferida'));
        if ($previousAdmin) {
            Mail::raw('A transferência de administração da empresa foi concluída.', fn ($mail) => $mail->to($previousAdmin)->subject('Fokus Cloud: administração transferida'));
        }
        return response()->json(['message' => 'Administração transferida com sucesso.']);
    }

    public function sendToken(User $user, string $purpose, string $path, array $payload = [], ?string $recipient = null): void
    {
        $plain = Str::random(64);
        DB::table('security_tokens')->where('user_id', $user->id)->where('purpose', $purpose)->whereNull('used_at')->update(['expires_at' => now(), 'updated_at' => now()]);
        DB::table('security_tokens')->insert([
            'id' => PrefixedUlid::make('TKN'), 'user_id' => $user->id, 'purpose' => $purpose,
            'token_hash' => hash('sha256', $plain), 'payload' => $payload ? json_encode($payload) : null,
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = rtrim(config('app.url'), '/').$path.'?token='.$plain;
        $subject = match ($purpose) {
            'email_verification' => 'Fokus Cloud: confirme seu e-mail',
            'password_reset', 'password_creation' => 'Fokus Cloud: crie ou redefina sua senha',
            'membership_acceptance' => 'Fokus Cloud: aceite seu vínculo',
            'admin_transfer' => 'Fokus Cloud: aceite a administração da empresa',
            default => 'Fokus Cloud: continue seu acesso',
        };
        Mail::raw("Use este link em até 24 horas para continuar: {$url}", fn ($mail) => $mail->to($recipient ?: $user->email)->subject($subject));
    }

    private function companyRegistrationData(Request $request, bool $newUser): array
    {
        $rules = [
            'document_type' => ['required', Rule::in(['cpf', 'cnpj'])],
            'document_number' => ['required', 'string'],
            'legal_name' => ['required', 'string', 'max:255'],
            'terms_version' => ['required', 'string', 'max:64'],
            'privacy_version' => ['required', 'string', 'max:64'],
            'return_to' => ['nullable', Rule::in(['/assinaturas/fokus-law', '/assinaturas/fokus-lead'])],
        ];
        if ($newUser) {
            $rules += ['name' => ['required', 'string', 'max:255'], 'cpf' => ['required', 'string'], 'email' => ['required', 'email:rfc', 'max:255'], 'password' => ['required', 'string', 'min:12']];
        }
        return $request->validate($rules);
    }

    private function validateDocuments(string $type, string $document, string $cpf): void
    {
        $this->validateCompanyDocument($type, $document);
        if (! BrazilianDocuments::cpf($cpf)) {
            throw ValidationException::withMessages(['cpf' => 'CPF inválido.']);
        }
    }

    private function validateCompanyDocument(string $type, string $document): void
    {
        abort_unless($type === 'cpf' ? BrazilianDocuments::cpf($document) : BrazilianDocuments::cnpj($document), 422, 'Documento empresarial inválido.');
    }

    private function assertCompanyIsAvailable(string $type, string $document): void
    {
        abort_if(DB::table('companies')->where('document_type', $type)->where('document_number', $document)->exists(), 409, 'Esta empresa já possui cadastro. Entre com sua conta para continuar.');
    }

    private function createCompanyFor(User $user, array $data, string $document): string
    {
        $companyId = PrefixedUlid::make('EMP');
        DB::table('companies')->insert([
            'id' => $companyId, 'document_type' => $data['document_type'], 'document_number' => $document,
            'legal_name' => $data['legal_name'], 'status' => $user->email_verified_at ? 'ativa' : 'pendente', 'version' => 1,
            'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $adminRole = DB::table('roles')->where('code', 'admin')->value('id');
        abort_unless($adminRole, 503, 'Perfis da plataforma ainda não foram configurados.');
        DB::table('company_memberships')->insert([
            'id' => PrefixedUlid::make('VNC'), 'company_id' => $companyId, 'user_id' => $user->id,
            'role_id' => $adminRole, 'status' => 'ativo', 'active_admin_company_id' => $companyId, 'version' => 1,
            'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($companyId, $user->id, 'company', $companyId, 'create', null, ['document_type' => $data['document_type'], 'legal_name' => $data['legal_name']]);
        return $companyId;
    }

    private function recordLegalAcceptances(User $user, array $data): void
    {
        foreach (['terms' => $data['terms_version'], 'privacy' => $data['privacy_version']] as $type => $version) {
            $query = DB::table('legal_acceptances')->where('user_id', $user->id)->where('document_type', $type)->where('document_version', $version);
            if ($query->exists()) {
                $query->update(['accepted_at' => now(), 'updated_at' => now()]);
                continue;
            }
            DB::table('legal_acceptances')->insert([
                'id' => PrefixedUlid::make('ACE'), 'user_id' => $user->id, 'document_type' => $type,
                'document_version' => $version, 'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function consumeToken(string $plain, string|array $purpose): object
    {
        return DB::transaction(function () use ($plain, $purpose) {
            $token = DB::table('security_tokens')->where('token_hash', hash('sha256', $plain))->whereIn('purpose', (array) $purpose)
                ->whereNull('used_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($token, 422, 'Link inválido ou expirado.');
            $used = DB::table('security_tokens')->where('id', $token->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
            abort_unless($used, 422, 'Link inválido ou expirado.');
            return $token;
        });
    }

    private function companiesFor(User $user): array
    {
        $lawNames = DB::table('subscriptions as subscription')->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('subscription.status', 'ativa')->whereIn('product.code', ['law', 'fokus-law'])
            ->whereNotNull('subscription.public_name')->where('subscription.public_name', '!=', '')
            ->groupBy('subscription.company_id')->select('subscription.company_id', DB::raw('MIN(subscription.public_name) as public_name'));

        return DB::table('company_memberships as membership')->join('companies as company', 'company.id', '=', 'membership.company_id')
            ->join('roles as role', 'role.id', '=', 'membership.role_id')->where('membership.user_id', $user->id)
            ->where('membership.status', 'ativo')->whereNull('membership.deleted_at')->whereNull('company.deleted_at')
            ->leftJoinSub($lawNames, 'law_name', 'law_name.company_id', '=', 'company.id')
            ->select('company.id', DB::raw('COALESCE(law_name.public_name, company.legal_name) as name'), 'company.legal_name as legal_name', 'role.code as role')
            ->orderBy('company.legal_name')->get()->unique('id')->values()->all();
    }

    private function userPayload(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'cpf' => $user->cpf, 'email_verified' => (bool) $user->email_verified_at, 'status' => $user->status];
    }

    private function firstCompanyId(User $user): ?string
    {
        return DB::table('company_memberships')->where('user_id', $user->id)->where('status', 'ativo')->whereNull('deleted_at')->value('company_id');
    }

    private function authenticateIntoSession(Request $request, User $user, ?string $companyId = null): void
    {
        app(\App\Services\SupportSessionSecurity::class)->end($request, 'Acesso de suporte encerrado por novo login de cliente.', true);
        $request->session()->regenerate();
        Auth::guard('web')->login($user);
        $request->session()->forget('active_company_id');
        if ($companyId) {
            $request->session()->put('active_company_id', $companyId);
        }
        $request->session()->save();
    }

    private function recordFailedLogin(User $user): void
    {
        $windowStart = $user->login_attempt_window_started_at;
        $attempts = $windowStart && $windowStart->gt(now()->subMinutes(15)) ? $user->failed_login_attempts + 1 : 1;
        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'login_attempt_window_started_at' => now(),
            'locked_until' => $attempts >= 5 ? now()->addMinutes(30) : null,
            'status' => $attempts >= 5 ? 'bloqueada' : $user->status,
        ])->save();
    }

    private function audit(string $companyId, ?string $actorId, string $entityType, string $entityId, string $operation, ?array $before, ?array $after): void
    {
        app(\App\Services\AuditRecorder::class)->company($companyId, $actorId, $entityType, $entityId, $operation, $before, $after, request: request());
    }
}
