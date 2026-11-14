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
        $request->validate(['q' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1']]);
        $ready = (bool) DB::table('interest_product_index_state')->where('id', 1)->value('ready');
        $catalogAccess = $request->user()->hasPermission('platform.catalog.manage');
        $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim((string) $request->query('q', ''))).'%';
        $pageNumber = (int) $request->query('page', 1);
        $historical = $ready ? DB::table('interest_product_index')->select('product_code as code')->distinct()
            ->whereRaw("product_code LIKE ? ESCAPE '!'", [$prefix])->orderBy('product_code')->simplePaginate(20) : null;
        $catalog = $catalogAccess ? DB::table('products')->select('code', 'name')
            ->whereRaw("code LIKE ? ESCAPE '!'", [$prefix])->orderBy('code')->simplePaginate(20) : null;
        $options = [];
        foreach ($catalog?->items() ?? [] as $product) {
            $options[$product->code] = ['code' => (string) $product->code, 'name' => $product->name];
        }
        foreach ($historical?->items() ?? [] as $product) {
            $options[$product->code] ??= ['code' => (string) $product->code, 'name' => (string) $product->code];
        }

        return response()->json(['products' => array_values($options), 'meta' => [
            'current_page' => $pageNumber, 'has_more' => ($historical?->hasMorePages() ?? false) || ($catalog?->hasMorePages() ?? false),
        ], 'historical_index_ready' => $ready]);
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
