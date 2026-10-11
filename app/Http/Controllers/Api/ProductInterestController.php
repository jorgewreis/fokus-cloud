<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ProductInterestNotification;
use App\Models\PlatformAdmin;
use App\Models\ProductInterest;
use App\Services\PrefixedUlid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductInterestController extends Controller
{
    public const PRIVACY_VERSION = '1.0';

    public function store(Request $request)
    {
        abort_if($request->filled('website'), 422, 'Não foi possível registrar o interesse.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'products' => ['required', 'array', 'min:1'],
            'products.*' => ['required', Rule::in(['law', 'lead'])],
            'profiles' => ['required', 'array', 'min:1'],
            'profiles.*' => ['nullable', 'string', 'max:80'],
            'organization' => ['nullable', 'string', 'max:180'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'size:2'],
            'team_size' => ['nullable', 'string', 'max:80'],
            'desired_capacity' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'catalog_max_capacity' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'current_process' => ['nullable', 'string', 'max:4000'],
            'main_difficulties' => ['required', 'string', 'max:4000'],
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', 'max:100'],
            'request_context' => ['nullable', Rule::in(['law_over_limit', 'law_pricing'])],
            'source_url' => ['nullable', 'url', 'max:500'],
            'privacy_accepted' => ['accepted'],
            'privacy_version' => ['required', 'string', 'max:64'],
        ]);

        abort_unless($data['privacy_version'] === self::PRIVACY_VERSION, 422, 'Atualize a página para aceitar a versão atual da política de privacidade.');

        $products = array_values(array_unique($data['products']));
        $profiles = array_values(array_filter(array_unique($data['profiles'])));
        $modules = array_values(array_filter(array_unique($data['modules'] ?? [])));
        $email = Str::lower(trim($data['email']));

        $interest = DB::transaction(function () use ($data, $products, $profiles, $modules, $email) {
            $interest = ProductInterest::where('email', $email)->lockForUpdate()->first();
            $attributes = [
                'name' => trim($data['name']),
                'email' => $email,
                'products' => $products,
                'profiles' => $profiles,
                'organization' => $data['organization'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => isset($data['state']) ? Str::upper($data['state']) : null,
                'team_size' => $data['team_size'] ?? null,
                'desired_capacity' => $data['desired_capacity'] ?? null,
                'catalog_max_capacity' => $data['catalog_max_capacity'] ?? null,
                'current_process' => $data['current_process'] ?? null,
                'main_difficulties' => $data['main_difficulties'],
                'modules' => $modules,
                'request_context' => $data['request_context'] ?? null,
                'source_url' => $data['source_url'] ?? null,
                'privacy_version' => self::PRIVACY_VERSION,
                'consented_at' => now(),
                'last_submitted_at' => now(),
            ];
            if ($interest) {
                $interest->fill($attributes)->save();
                return $interest;
            }
            return ProductInterest::create(['id' => PrefixedUlid::make('INT'), 'status' => 'novo', ...$attributes]);
        });

        $adminRecipients = PlatformAdmin::query()
            ->where('status', 'ativo')
            ->whereNull('deactivated_at')
            ->whereHas('role.permissions', fn ($query) => $query->where('code', 'platform.product_interests.manage'))
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        abort_if(empty($adminRecipients), 503, 'Seu interesse foi registrado, mas não foi possível encaminhá-lo à equipe neste momento.');

        Mail::to($adminRecipients)->send(
            (new ProductInterestNotification($interest->fresh()))
                ->replyTo($interest->email, $interest->name)
        );

        $adminRecipients = PlatformAdmin::query()
            ->where('status', 'ativo')
            ->whereNull('deactivated_at')
            ->whereHas('role.permissions', fn ($query) => $query->where('code', 'platform.product_interests.manage'))
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        abort_if(empty($adminRecipients), 503, 'Seu interesse foi registrado, mas não foi possível encaminhá-lo à equipe neste momento.');

        Mail::to($adminRecipients)
            ->replyTo($interest->email, $interest->name)
            ->send(new ProductInterestNotification($interest->fresh()));

        return response()->json([
            'message' => 'Interesse registrado com sucesso.',
            'interest_id' => $interest->id,
        ], 201);
    }
}
