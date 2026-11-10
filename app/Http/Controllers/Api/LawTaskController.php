<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LawAuthorizationService;
use App\Services\LawCaseManagementService;
use App\Services\PrefixedUlid;
use App\Services\SubscriptionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LawTaskController extends Controller
{
    private function context(Request $request): array
    {
        abort_unless(config('law-features.tasks_enabled', false), 404);
        $company = (string) $request->attributes->get('active_company_id');
        $membership = $request->attributes->get('active_membership');
        abort_unless($request->user()?->status === 'ativa' && $request->user()?->email_verified_at && $membership && $membership->deleted_at === null, 403);
        abort_unless(DB::table('companies')->where('id', $company)->where('status', 'ativa')->whereNull('deleted_at')->exists(), 403);
        $contract = DB::table('subscription_items as item')->join('subscriptions as subscription', 'subscription.id', '=', 'item.subscription_id')
            ->join('products as product', 'product.id', '=', 'subscription.product_id')->join('modules as module', 'module.id', '=', 'item.module_id')
            ->where('subscription.company_id', $company)->where('item.company_id', $company)->whereNull('item.deleted_at')
            ->where(fn ($query) => SubscriptionAccess::usable($query))->whereIn('product.code', ['law', 'fokus-law'])
            ->where('module.module_code', 'tarefas')->where('module.context_code', 'escritorio')->where('module.status', 'ativo')->where('module.publication_state', 'publicado')->exists();
        abort_unless($contract, 403, 'Tarefas não estão habilitadas para este escritório.');
        $auth = app(LawAuthorizationService::class);
        $unit = $auth->activeUnitId($request);
        abort_unless($unit, 403, 'Selecione uma unidade autorizada.');

        return [$company, $unit];
    }

    private function authorize(Request $request, string $action, string $unit): void
    {
        app(LawAuthorizationService::class)->authorize($request, 'law.tasks.'.$action, $unit);
    }

    private function visible(Request $request, string $company, string $unit)
    {
        $query = DB::table('law_tasks')->where('law_tasks.company_id', $company)->where('law_tasks.law_unit_id', $unit)->whereNull('law_tasks.deleted_at');
        $canReadCases = app(LawAuthorizationService::class)->can($request, 'law.cases.view', $unit);

        return $query->where(function ($visible) use ($request, $company, $unit, $canReadCases): void {
            $visible->whereNull('law_tasks.law_case_id');
            if ($canReadCases) {
                $visible->orWhereIn('law_tasks.law_case_id', $this->visibleCases($request, $company, $unit)->select('law_cases.id'));
            }
        });
    }

    private function visibleCases(Request $request, string $company, string $unit)
    {
        $query = DB::table('law_cases')->where('law_cases.company_id', $company)->where('law_cases.law_unit_id', $unit);

        return app(LawCaseManagementService::class)->visibleQuery($request, $query);
    }

    private function find(Request $request, string $id, string $company, string $unit): object
    {
        $query = $this->visible($request, $company, $unit)->where('law_tasks.id', $id);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $task = $query->first();
        abort_unless($task, 404, 'Tarefa não encontrada.');

        return $task;
    }

    private function responsibles(string $company, string $unit)
    {
        return DB::table('company_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('roles as role', 'role.id', '=', 'm.role_id')
            ->where('m.company_id', $company)->where('m.status', 'ativo')->whereNull('m.deleted_at')->where('u.status', 'ativa')->whereNotNull('u.email_verified_at')
            ->where(function ($eligible) use ($company, $unit): void {
                $eligible->where('role.code', 'admin')->orWhereExists(function ($local) use ($company, $unit): void {
                    $local->selectRaw('1')->from('law_unit_memberships as lum')->whereColumn('lum.company_membership_id', 'm.id')
                        ->where('lum.company_id', $company)->where('lum.law_unit_id', $unit)->where('lum.status', 'ativo')->whereNull('lum.deleted_at');
                });
            });
    }

    private function fields(Request $request, string $company, string $unit, bool $create, ?object $current = null): array
    {
        $data = $request->validate([
            'company_id' => ['prohibited'], 'law_unit_id' => ['prohibited'], 'created_by' => ['prohibited'], 'updated_by' => ['prohibited'],
            'title' => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:5000'],
            'due_on' => ['nullable', 'date_format:Y-m-d'], 'responsible_membership_id' => ['nullable', 'string', 'max:30'], 'law_case_id' => ['nullable', 'string', 'max:30'],
        ]);
        if ($current && array_key_exists('responsible_membership_id', $data) && $data['responsible_membership_id'] === $current->responsible_membership_id) {
            unset($data['responsible_membership_id']);
        }
        if (isset($data['responsible_membership_id'])) {
            abort_unless($this->responsibles($company, $unit)->where('m.id', $data['responsible_membership_id'])->exists(), 422, 'Responsável não pertence ao escritório ativo.');
        }
        if ($current) {
            abort_if(array_key_exists('law_case_id', $data) && $data['law_case_id'] !== $current->law_case_id, 422, 'O vínculo processual não pode ser alterado. Cadastre outra tarefa.');
        }
        if (! empty($data['law_case_id'])) {
            app(LawAuthorizationService::class)->authorize($request, 'law.cases.view', $unit);
            abort_unless($this->visibleCases($request, $company, $unit)->where('law_cases.id', $data['law_case_id'])->lockForUpdate()->first(), 404, 'Processo não encontrado.');
        }

        return $data;
    }

    private function taskArray(object $row): array
    {
        return array_diff_key((array) $row, array_flip(['request_key', 'request_fingerprint', 'deleted_at']));
    }

    private function event(Request $request, object $row, string $type, ?array $before, ?array $after): void
    {
        DB::table('law_task_events')->insert(['id' => PrefixedUlid::make('LTE'), 'company_id' => $row->company_id, 'law_task_id' => $row->id,
            'actor_user_id' => $request->user()->id, 'event_type' => $type, 'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function index(Request $request)
    {
        [$company, $unit] = $this->context($request);
        $this->authorize($request, 'view', $unit);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'status' => ['sometimes', Rule::in(['pending', 'completed'])], 'q' => ['nullable', 'string', 'max:180']]);
        $query = $this->visible($request, $company, $unit);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $query->where('title', 'like', '%'.$data['q'].'%');
        }
        $page = $query->orderBy('due_on')->orderBy('id')->paginate($data['per_page'] ?? 20);
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->taskArray($row)));

        return response()->json($page);
    }

    public function references(Request $request)
    {
        [$company, $unit] = $this->context($request);
        $this->authorize($request, 'view', $unit);
        $data = $request->validate(['case_q' => ['nullable', 'string', 'max:180'], 'case_page' => ['sometimes', 'integer', 'min:1']]);
        $cases = ['data' => [], 'total' => 0, 'last_page' => 1];
        if (app(LawAuthorizationService::class)->can($request, 'law.cases.view', $unit)) {
            $query = $this->visibleCases($request, $company, $unit);
            if (! empty($data['case_q'])) {
                $query->where('case_number', 'like', '%'.$data['case_q'].'%');
            }
            $cases = $query->select('id', 'case_number')->orderBy('id')->paginate(20, ['*'], 'case_page');
        }

        return response()->json(['responsibles' => $this->responsibles($company, $unit)->orderBy('u.name')->get(['m.id', 'u.name']), 'cases' => $cases]);
    }

    public function show(Request $request, string $task)
    {
        [$company, $unit] = $this->context($request);
        $this->authorize($request, 'view', $unit);
        $row = $this->find($request, $task, $company, $unit);
        $events = DB::table('law_task_events')->where('company_id', $company)->where('law_task_id', $task)->orderBy('created_at')->orderBy('id')->get()->map(function ($event) {
            foreach (['before_state', 'after_state'] as $field) {
                $event->$field = $event->$field === null ? null : json_decode($event->$field, true);
            }

            return $event;
        });

        return response()->json(['task' => $this->taskArray($row), 'events' => $events]);
    }

    public function store(Request $request)
    {
        [$company, $unit] = $this->context($request);
        $this->authorize($request, 'create', $unit);
        $key = $request->validate(['request_key' => ['required', 'uuid']])['request_key'];
        // Canonical payload includes explicit nulls. Reusing a key with a different payload is rejected.
        $payload = $request->except('request_key');
        ksort($payload);
        $fingerprint = hash('sha256', json_encode([$unit, $payload], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $company, $unit, $key, $fingerprint) {
            // Serialize concurrent creates for this actor and tenant without a second ledger table.
            DB::table('company_memberships')->where('company_id', $company)->where('id', $request->attributes->get('active_membership')->id)->lockForUpdate()->first();
            $existing = DB::table('law_tasks')->where('company_id', $company)->where('created_by', $request->user()->id)->where('request_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_fingerprint, $fingerprint) && ! $existing->deleted_at, 409, 'Chave de solicitação já utilizada.');
                $row = $this->find($request, $existing->id, $company, $unit);

                return response()->json(['task' => $this->taskArray($row), 'replayed' => true]);
            }
            $data = $this->fields($request, $company, $unit, true);
            $id = PrefixedUlid::make('LTA');
            DB::table('law_tasks')->insert($data + ['id' => $id, 'company_id' => $company, 'law_unit_id' => $unit, 'status' => 'pending', 'version' => 1,
                'request_key' => $key, 'request_fingerprint' => $fingerprint, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $row = $this->find($request, $id, $company, $unit);
            $this->event($request, $row, 'created', null, $this->taskArray($row));

            return response()->json(['task' => $this->taskArray($row), 'replayed' => false], 201);
        });
    }

    public function update(Request $request, string $task)
    {
        return $this->mutate($request, $task, 'update');
    }

    public function status(Request $request, string $task)
    {
        return $this->mutate($request, $task, 'status.update');
    }

    public function destroy(Request $request, string $task)
    {
        return $this->mutate($request, $task, 'delete');
    }

    private function mutate(Request $request, string $task, string $action)
    {
        [$company, $unit] = $this->context($request);
        $this->authorize($request, $action, $unit);
        $request->validate(['version' => ['required', 'integer', 'min:1'], 'company_id' => ['prohibited'], 'law_unit_id' => ['prohibited']]);

        return DB::transaction(function () use ($request, $task, $company, $unit, $action) {
            $row = $this->find($request, $task, $company, $unit);
            abort_unless((int) $row->version === $request->integer('version'), 409, 'Tarefa alterada. Reabra antes de salvar.');
            $data = match ($action) {
                'update' => $this->fields($request, $company, $unit, false, $row),
                'status.update' => $request->validate(['status' => ['required', Rule::in(['pending', 'completed'])]]),
                'delete' => ['deleted_at' => now()->toDateTimeString()],
            };
            $diff = array_filter($data, fn ($value, $field) => $value !== $row->$field, ARRAY_FILTER_USE_BOTH);
            if ($diff !== []) {
                $before = array_intersect_key((array) $row, $diff);
                DB::table('law_tasks')->where('company_id', $company)->where('id', $task)->update($diff + ['version' => $row->version + 1, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
                $this->event($request, $row, $action === 'delete' ? 'deleted' : 'updated', $before, $diff);
            }

            return $action === 'delete' ? response()->noContent() : response()->json(['task' => $this->taskArray($this->find($request, $task, $company, $unit))]);
        });
    }
}
