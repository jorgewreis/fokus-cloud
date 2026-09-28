<?php

use App\Services\PrefixedUlid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['law.company.view', 'Visualizar dados da empresa', 'company', 'view'],
        ['law.company.update', 'Alterar dados da empresa', 'company', 'update'],
        ['law.units.view', 'Visualizar setores', 'units', 'view'],
        ['law.units.manage', 'Gerenciar setores', 'units', 'manage'],
        ['law.subscription.view', 'Visualizar assinatura', 'subscription', 'view'],
        ['law.subscription.manage', 'Gerenciar assinatura', 'subscription', 'manage'],
        ['law.notifications.view', 'Visualizar notificações', 'notifications', 'view'],
        ['law.notifications.update', 'Alterar notificações', 'notifications', 'update'],
        ['law.contacts.view', 'Visualizar contatos', 'contacts', 'view'],
        ['law.contacts.create', 'Criar contatos', 'contacts', 'create'],
        ['law.contacts.update', 'Alterar contatos', 'contacts', 'update'],
        ['law.contacts.delete', 'Inativar ou remover contatos', 'contacts', 'delete'],
        ['law.hearings.view', 'Visualizar audiências', 'hearings', 'view'],
        ['law.hearings.create', 'Criar audiências', 'hearings', 'create'],
        ['law.hearings.update', 'Alterar audiências', 'hearings', 'update'],
        ['law.hearings.delete', 'Remover audiências', 'hearings', 'delete'],
        ['law.hearings.status.update', 'Alterar situação de audiências', 'hearings', 'status.update'],
        ['law.hearings.external_access.manage', 'Gerenciar acessos externos de audiências', 'hearings', 'external_access.manage'],
        ['law.users.view', 'Visualizar usuários do setor', 'users', 'view'],
        ['law.users.manage', 'Gerenciar usuários do setor', 'users', 'manage'],
        ['law.roles.view', 'Visualizar perfis do setor', 'roles', 'view'],
        ['law.roles.manage', 'Criar e alterar perfis personalizados', 'roles', 'manage'],
    ];

    private const ROLE_PERMISSIONS = [
        'unit_admin' => [
            'law.company.view', 'law.notifications.view', 'law.notifications.update',
            'law.contacts.view', 'law.contacts.create', 'law.contacts.update', 'law.contacts.delete',
            'law.hearings.view', 'law.hearings.create', 'law.hearings.update', 'law.hearings.delete',
            'law.hearings.status.update', 'law.hearings.external_access.manage',
            'law.users.view', 'law.users.manage', 'law.roles.view', 'law.roles.manage',
        ],
        'chief_clerk' => [
            'law.company.view', 'law.units.view', 'law.notifications.view', 'law.notifications.update',
            'law.contacts.view', 'law.contacts.create', 'law.contacts.update', 'law.contacts.delete',
            'law.hearings.view', 'law.hearings.create', 'law.hearings.update', 'law.hearings.delete',
            'law.hearings.status.update', 'law.hearings.external_access.manage',
            'law.users.view', 'law.users.manage', 'law.roles.view',
        ],
        'operator' => [
            'law.company.view', 'law.notifications.view', 'law.notifications.update',
            'law.contacts.view', 'law.contacts.create', 'law.contacts.update',
            'law.hearings.view', 'law.hearings.create', 'law.hearings.update', 'law.hearings.status.update',
        ],
        'viewer' => [
            'law.company.view', 'law.notifications.view', 'law.contacts.view', 'law.hearings.view', 'law.roles.view',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('customer_permissions')) Schema::create('customer_permissions', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->string('code', 100)->unique();
            $table->string('product_code', 32);
            $table->string('resource', 48);
            $table->string('action', 48);
            $table->string('description', 180);
            $table->timestamps();
            $table->index(['product_code', 'resource'], 'customer_permissions_product_resource_idx');
        });

        if (! Schema::hasTable('law_access_roles')) Schema::create('law_access_roles', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_unit_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('code', 80);
            $table->string('name', 100);
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'law_unit_id', 'code'], 'law_access_roles_scope_code_unique');
            $table->unique(['company_id', 'law_unit_id', 'id'], 'law_access_roles_scope_id_unique');
            $table->foreign(['company_id', 'law_unit_id'])->references(['company_id', 'id'])->on('law_units')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        if (! Schema::hasTable('law_access_role_permissions')) Schema::create('law_access_role_permissions', function (Blueprint $table): void {
            $table->char('law_access_role_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('customer_permission_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->primary(['law_access_role_id', 'customer_permission_id'], 'law_role_permissions_primary');
            $table->foreign('law_access_role_id')->references('id')->on('law_access_roles')->cascadeOnDelete();
            $table->foreign('customer_permission_id')->references('id')->on('customer_permissions')->restrictOnDelete();
        });

        if (! Schema::hasTable('law_unit_memberships')) Schema::create('law_unit_memberships', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_unit_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('company_membership_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_access_role_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('status', 16)->default('ativo');
            $table->unsignedInteger('version')->default(1);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->char('updated_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'law_unit_id', 'company_membership_id'], 'law_unit_memberships_subject_unique');
            $table->foreign(['company_id', 'law_unit_id'])->references(['company_id', 'id'])->on('law_units')->cascadeOnDelete();
            $table->foreign(['company_id', 'company_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->cascadeOnDelete();
            $table->foreign(['company_id', 'law_unit_id', 'law_access_role_id'], 'law_membership_role_scope_fk')->references(['company_id', 'law_unit_id', 'id'])->on('law_access_roles')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'law_unit_id', 'status'], 'law_unit_memberships_scope_status_idx');
        });

        foreach (self::PERMISSIONS as [$code, $description, $resource, $action]) {
            $permission = DB::table('customer_permissions')->where('code', $code)->first();
            $values = ['product_code' => 'law', 'resource' => $resource, 'action' => $action, 'description' => $description, 'updated_at' => now()];
            if ($permission) DB::table('customer_permissions')->where('id', $permission->id)->update($values);
            else DB::table('customer_permissions')->insert($values + ['id' => PrefixedUlid::make('CPM'), 'code' => $code, 'created_at' => now()]);
        }

        $units = DB::table('law_units')->where('status', 'ativo')->get(['id', 'company_id', 'created_by']);
        foreach ($units as $unit) {
            $roleIds = $this->createUnitRoles((string) $unit->company_id, (string) $unit->id, (string) ($unit->created_by ?? ''));
            $members = DB::table('company_memberships as membership')
                ->join('roles', 'roles.id', '=', 'membership.role_id')
                ->where('membership.company_id', $unit->company_id)
                ->where('membership.status', 'ativo')->whereNull('membership.deleted_at')
                ->whereIn('roles.code', ['gestor', 'usuario'])
                ->get(['membership.id', 'membership.user_id', 'membership.role_id', 'roles.code as legacy_role']);
            foreach ($members as $member) {
                $roleCode = $member->legacy_role === 'gestor' ? 'chief_clerk' : 'operator';
                $exists = DB::table('law_unit_memberships')->where('company_id', $unit->company_id)->where('law_unit_id', $unit->id)
                    ->where('company_membership_id', $member->id)->exists();
                if ($exists) continue;
                DB::table('law_unit_memberships')->insert([
                    'id' => PrefixedUlid::make('LUM'), 'company_id' => $unit->company_id,
                    'law_unit_id' => $unit->id, 'company_membership_id' => $member->id,
                    'law_access_role_id' => $roleIds[$roleCode], 'status' => 'ativo', 'version' => 1,
                    'created_by' => $unit->created_by, 'updated_by' => $unit->created_by,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function createUnitRoles(string $companyId, string $unitId, string $createdBy): array
    {
        $permissionIds = DB::table('customer_permissions')->pluck('id', 'code')->all();
        $ids = [];
        foreach (self::ROLE_PERMISSIONS as $code => $codes) {
            $existing = DB::table('law_access_roles')->where('company_id', $companyId)->where('law_unit_id', $unitId)->where('code', $code)->first();
            $id = $existing?->id ?: PrefixedUlid::make('LAR');
            $ids[$code] = $id;
            $values = [
                'name' => match ($code) {
                    'unit_admin' => 'Administrador do setor', 'chief_clerk' => 'Chefe / Escrivão',
                    'operator' => 'Operador', default => 'Somente leitura',
                }, 'is_system' => true, 'updated_at' => now(),
            ];
            if ($existing) DB::table('law_access_roles')->where('id', $id)->update($values);
            else DB::table('law_access_roles')->insert($values + [
                'id' => $id, 'company_id' => $companyId, 'law_unit_id' => $unitId, 'code' => $code,
                'created_by' => $createdBy ?: null, 'created_at' => now(),
            ]);
            $granted = array_map(fn (string $permission): string => $permissionIds[$permission], $codes);
            foreach ($granted as $permissionId) {
                DB::table('law_access_role_permissions')->insertOrIgnore(['law_access_role_id' => $id, 'customer_permission_id' => $permissionId]);
            }
        }
        return $ids;
    }

    public function down(): void
    {
        Schema::dropIfExists('law_unit_memberships');
        Schema::dropIfExists('law_access_role_permissions');
        Schema::dropIfExists('law_access_roles');
        Schema::dropIfExists('customer_permissions');
    }
};
