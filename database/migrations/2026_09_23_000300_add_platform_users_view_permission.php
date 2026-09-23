<?php

use App\Services\PrefixedUlid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $permissionId = PrefixedUlid::make('PPM');
        DB::table('platform_permissions')->insert([
            'id' => $permissionId,
            'code' => 'platform.users.view',
            'name' => 'Consultar usuarios da plataforma e das empresas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roles = DB::table('platform_roles')->whereIn('code', ['superadministrador', 'administrador_comercial'])->pluck('id');
        foreach ($roles as $roleId) {
            DB::table('platform_role_permissions')->insert([
                'platform_role_id' => $roleId,
                'platform_permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('platform_permissions')->where('code', 'platform.users.view')->value('id');
        if ($permissionId) {
            DB::table('platform_role_permissions')->where('platform_permission_id', $permissionId)->delete();
            DB::table('platform_permissions')->where('id', $permissionId)->delete();
        }
    }
};
