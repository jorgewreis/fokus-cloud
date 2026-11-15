<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LawBetaApplication;
use App\Services\PrefixedUlid;
use App\Support\LegalDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LawBetaApplicationController extends Controller
{
    public const CAMPAIGN_CODE = 'law_advocacia_beta_6m';

    public const BETA_PROGRAM_VERSION = '1.0';

    private const PROFILE = 'Advocacia';

    private const MODULES = ['Gestão de Contatos', 'Gestão de Processos'];

    public function store(Request $request)
    {
        abort_if($request->filled('website'), 422, 'Não foi possível registrar sua candidatura ao beta.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'organization' => ['nullable', 'string', 'max:180'],
            'feedback' => ['required', 'string', 'max:4000'],
            'privacy_accepted' => ['accepted'],
            'privacy_version' => ['required', 'string', 'max:64'],
            'beta_terms_accepted' => ['accepted'],
            'beta_program_version' => ['required', 'string', 'max:64'],
            'source_url' => ['nullable', 'url', 'max:500'],
        ]);

        abort_unless($data['privacy_version'] === LegalDocuments::PRIVACY_VERSION, 422, 'Atualize a página para aceitar a versão atual da política de privacidade.');
        abort_unless($data['beta_program_version'] === self::BETA_PROGRAM_VERSION, 422, 'Atualize a página para aceitar as condições atuais do programa beta.');

        $email = Str::lower(trim($data['email']));
        $created = false;

        $application = DB::transaction(function () use ($data, $email, &$created) {
            $application = LawBetaApplication::query()
                ->where('campaign_code', self::CAMPAIGN_CODE)
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'name' => trim($data['name']),
                'email' => $email,
                'organization' => filled($data['organization'] ?? null) ? trim($data['organization']) : null,
                'profile' => self::PROFILE,
                'modules' => self::MODULES,
                'feedback' => trim($data['feedback']),
                'source_url' => $data['source_url'] ?? null,
                'privacy_version' => LegalDocuments::PRIVACY_VERSION,
                'privacy_consented_at' => now(),
                'beta_program_version' => self::BETA_PROGRAM_VERSION,
                'beta_terms_accepted_at' => now(),
            ];

            if ($application) {
                $application->fill($attributes)->save();

                return $application;
            }

            $created = true;

            return LawBetaApplication::create([
                'id' => PrefixedUlid::make('LBA'),
                'campaign_code' => self::CAMPAIGN_CODE,
                'status' => 'novo',
                ...$attributes,
            ]);
        });

        return response()->json([
            'message' => $created ? 'Candidatura ao beta registrada com sucesso.' : 'Candidatura ao beta atualizada com sucesso.',
            'application_id' => $application->id,
        ], $created ? 201 : 200);
    }
}
