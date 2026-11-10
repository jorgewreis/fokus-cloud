<?php

use App\Services\PrefixedUlid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['view' => 'Consultar tarefas', 'create' => 'Criar tarefas', 'update' => 'Editar tarefas', 'status.update' => 'Alterar situação das tarefas', 'delete' => 'Excluir tarefas'];

    public function up(): void
    {
        Schema::create('law_tasks', function (Blueprint $table): void {
            foreach (['id', 'company_id', 'law_unit_id', 'created_by', 'updated_by'] as $column) {
                $table->char($column, 30)->charset('ascii')->collation('ascii_bin');
            }
            $table->primary('id');
            foreach (['law_case_id', 'responsible_membership_id'] as $column) {
                $table->char($column, 30)->charset('ascii')->collation('ascii_bin')->nullable();
            }
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->uuid('request_key')->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'id'], 'law_tasks_tenant_id_uq');
            $table->unique(['company_id', 'created_by', 'request_key'], 'law_tasks_request_uq');
            $table->foreign(['company_id', 'law_unit_id'])->references(['company_id', 'id'])->on('law_units')->restrictOnDelete();
            $table->foreign(['company_id', 'law_unit_id', 'law_case_id'])->references(['company_id', 'law_unit_id', 'id'])->on('law_cases')->restrictOnDelete();
            $table->foreign(['company_id', 'responsible_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['company_id', 'law_unit_id', 'status', 'due_on'], 'law_tasks_due_idx');
        });
        Schema::create('law_task_events', function (Blueprint $table): void {
            foreach (['id', 'company_id', 'law_task_id', 'actor_user_id'] as $column) {
                $table->char($column, 30)->charset('ascii')->collation('ascii_bin');
            }
            $table->primary('id');
            $table->string('event_type', 32);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'law_task_id'])->references(['company_id', 'id'])->on('law_tasks')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['company_id', 'law_task_id', 'created_at'], 'law_task_events_history_idx');
        });
        foreach (self::PERMISSIONS as $action => $description) {
            DB::table('customer_permissions')->insertOrIgnore([
                'id' => PrefixedUlid::make('CPM'), 'code' => 'law.tasks.'.$action, 'product_code' => 'law', 'resource' => 'tasks', 'action' => $action, 'description' => $description, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // New permissions require explicit grants. Do not modify existing role policy.
    }

    public function down(): void
    {
        // Destructive schema reversal for controlled tests, not production recovery.
        Schema::dropIfExists('law_task_events');
        Schema::dropIfExists('law_tasks');
        $codes = array_map(fn ($action) => 'law.tasks.'.$action, array_keys(self::PERMISSIONS));
        $ids = DB::table('customer_permissions')->where('product_code', 'law')->whereIn('code', $codes)->pluck('id');
        DB::table('law_access_role_permissions')->whereIn('customer_permission_id', $ids)->delete();
        DB::table('customer_permissions')->whereIn('id', $ids)->delete();
    }
};
