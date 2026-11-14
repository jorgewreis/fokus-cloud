<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductInterest;
use App\Services\PlatformAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductInterestBackofficeController extends Controller
{
    public function options(Request $request)
    {
        // Include inactive catalogue entries and codes retained in past interests,
        // even if their catalogue entry was subsequently removed.
        $options = $request->user()->hasPermission('platform.catalog.manage')
            ? DB::table('products')->orderBy('name')->get(['code', 'name'])->mapWithKeys(fn ($product) => [$product->code => $product->name])->all()
            : [];
        foreach (DB::table('product_interests')->distinct()->orderBy('products')->cursor(['products']) as $row) {
            $codes = json_decode((string) $row->products, true);
            if (! is_array($codes)) {
                continue;
            }
            foreach ($codes as $code) {
                if (is_string($code) && $code !== '' && ! array_key_exists($code, $options)) {
                    $options[$code] = $code;
                }
            }
        }

        return response()->json(['products' => collect($options)->map(fn ($name, $code) => ['code' => (string) $code, 'name' => $name])->values()]);
    }

    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $query = ProductInterest::query()
            ->when($request->query('product'), fn ($builder, $value) => $builder->whereJsonContains('products', $value))
            ->when($request->query('profile'), fn ($builder, $value) => $builder->whereJsonContains('profiles', $value))
            ->when($request->query('status'), fn ($builder, $value) => $builder->where('status', $value))
            ->latest('last_submitted_at');
        $paginator = $query->paginate($perPage);

        return response()->json(['data' => $paginator->items(), 'meta' => [
            'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
        ]]);
    }

    public function show(string $interest)
    {
        return response()->json(['interest' => ProductInterest::findOrFail($interest)]);
    }

    public function update(Request $request, string $interest, PlatformAudit $audit)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['novo', 'contatado', 'qualificado', 'arquivado'])]]);
        $entity = ProductInterest::findOrFail($interest);
        $before = $entity->only(['status']);
        $entity->update($data);
        $audit->record($request->user()->id, 'backoffice.product_interest_status_updated', 'product_interest', $entity->id, reason: 'Atualização do interesse de produto', before: $before, after: $data, request: $request);

        return response()->json(['message' => 'Status atualizado.', 'interest' => $entity->fresh()]);
    }
}
