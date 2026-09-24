<?php

use App\Services\PrefixedUlid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('refund_requests')) {
            Schema::create('refund_requests', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('subscription_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->char('payment_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('requested_by_platform_admin_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('approved_by_platform_admin_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->text('reason');
            $table->string('allowed_case', 64);
            $table->decimal('amount', 12, 2);
            $table->string('status', 32)->default('solicitado');
            $table->string('provider_refund_id', 128)->nullable()->unique();
            $table->json('provider_payload_sanitized')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->timestamps();
            $table->foreign('company_id', 'refund_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('subscription_id', 'refund_subscription_fk')->references('id')->on('subscriptions')->restrictOnDelete();
            $table->foreign('payment_id', 'refund_payment_fk')->references('id')->on('payments')->restrictOnDelete();
            $table->foreign('requested_by_platform_admin_id', 'refund_requester_fk')->references('id')->on('platform_admins')->restrictOnDelete();
            $table->foreign('approved_by_platform_admin_id', 'refund_approver_fk')->references('id')->on('platform_admins')->nullOnDelete();
            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['payment_id', 'status']);
            });
        }

        if (! Schema::hasTable('payment_reconciliation_alerts')) {
            Schema::create('payment_reconciliation_alerts', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('subscription_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->char('payment_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('fingerprint', 64)->unique();
            $table->string('platform_alert_id', 30)->nullable();
            $table->string('type', 80);
            $table->string('internal_status', 80)->nullable();
            $table->string('mercado_pago_status', 80)->nullable();
            $table->string('impact', 32)->default('medio');
            $table->char('reviewed_by_platform_admin_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->char('corrected_by_platform_admin_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->text('correction_reason')->nullable();
            $table->json('correction_snapshot')->nullable();
            $table->char('audit_event_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('status', 32)->default('aberta');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();
            $table->foreign('company_id', 'recon_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('subscription_id', 'recon_subscription_fk')->references('id')->on('subscriptions')->restrictOnDelete();
            $table->foreign('payment_id', 'recon_payment_fk')->references('id')->on('payments')->restrictOnDelete();
            $table->foreign('reviewed_by_platform_admin_id', 'recon_reviewer_fk')->references('id')->on('platform_admins')->nullOnDelete();
            $table->foreign('corrected_by_platform_admin_id', 'recon_corrector_fk')->references('id')->on('platform_admins')->nullOnDelete();
            $table->foreign('audit_event_id', 'recon_audit_fk')->references('id')->on('platform_audit_events')->nullOnDelete();
            $table->index(['status', 'impact', 'opened_at']);
            $table->index(['company_id', 'subscription_id', 'payment_id'], 'recon_company_sub_payment_idx');
            });
        }

        $permissions = [
            'platform.payments.view' => 'Consultar pagamentos',
            'platform.reconciliation.view' => 'Consultar conciliacao',
            'platform.refunds.request' => 'Solicitar reembolso',
            'platform.refunds.manage' => 'Aprovar e executar reembolso',
        ];
        foreach ($permissions as $code => $name) {
            $permissionId = DB::table('platform_permissions')->where('code', $code)->value('id');
            if (! $permissionId) {
                $permissionId = PrefixedUlid::make('PPM');
                DB::table('platform_permissions')->insert(['id' => $permissionId, 'code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }

            $superRoleId = DB::table('platform_roles')->where('code', 'superadministrador')->value('id');
            if ($superRoleId && ! DB::table('platform_role_permissions')->where(['platform_role_id' => $superRoleId, 'platform_permission_id' => $permissionId])->exists()) {
                DB::table('platform_role_permissions')->insert(['platform_role_id' => $superRoleId, 'platform_permission_id' => $permissionId]);
            }
        }

        $commercialRoleId = DB::table('platform_roles')->where('code', 'administrador_comercial')->value('id');
        foreach (['platform.payments.view', 'platform.reconciliation.view', 'platform.refunds.request'] as $code) {
            $permissionId = DB::table('platform_permissions')->where('code', $code)->value('id');
            if ($commercialRoleId && $permissionId && ! DB::table('platform_role_permissions')->where(['platform_role_id' => $commercialRoleId, 'platform_permission_id' => $permissionId])->exists()) {
                DB::table('platform_role_permissions')->insert(['platform_role_id' => $commercialRoleId, 'platform_permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_alerts');
        Schema::dropIfExists('refund_requests');
    }
};
