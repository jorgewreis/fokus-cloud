<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PrefixedUlid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsageSnapshotController extends Controller
{
    public function store(Request $request)
    {
        $secrets = array_filter([(string) config('services.usage.ingestion_secret'), ...config('services.usage.ingestion_previous_secrets', [])]);
        $valid = false;
        foreach ($secrets as $secret) {
            $valid = hash_equals($secret, (string) $request->header('X-Fokus-Usage-Secret')) || $valid;
        }
        abort_unless($valid, 401, 'Integração de uso não autenticada.');
        $data = $request->validate(['company_id' => ['required', 'string', 'size:30'], 'product_code' => ['required', 'in:law,lead'], 'reported_on' => ['required', 'date'], 'active_users' => ['required', 'integer', 'min:0'], 'licensed_seats' => ['required', 'integer', 'min:0'], 'used_seats' => ['required', 'integer', 'min:0'], 'key_records' => ['required', 'integer', 'min:0'], 'last_activity_at' => ['nullable', 'date'], 'metrics' => ['nullable', 'array']]);
        abort_if($data['used_seats'] > $data['licensed_seats'], 422, 'Assentos usados não podem exceder os licenciados.');
        $productId = DB::table('products')->where('code', $data['product_code'])->value('id');
        abort_unless($productId && DB::table('companies')->where('id', $data['company_id'])->exists(), 404, 'Empresa ou produto inválido.');
        $key = ['company_id' => $data['company_id'], 'product_id' => $productId, 'reported_on' => $data['reported_on']];
        $values = ['active_users' => $data['active_users'], 'licensed_seats' => $data['licensed_seats'], 'used_seats' => $data['used_seats'], 'key_records' => $data['key_records'], 'last_activity_at' => $data['last_activity_at'] ?? null, 'metrics' => isset($data['metrics']) ? json_encode($data['metrics']) : null, 'updated_at' => now()];
        $existing = DB::table('usage_snapshots')->where($key)->first();
        if ($existing) DB::table('usage_snapshots')->where('id', $existing->id)->update($values);
        else DB::table('usage_snapshots')->insert([...$key, ...$values, 'id' => PrefixedUlid::make('USG'), 'created_at' => now()]);
        return response()->json(['message' => 'Uso consolidado.'], 201);
    }
}
