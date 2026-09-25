<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('subscription_changes', 'subscription_changes_company_id_unique')) {
            Schema::table('subscription_changes', function (Blueprint $table): void {
                $table->unique(['company_id', 'id'], 'subscription_changes_company_id_unique');
            });
        }
        if (! Schema::hasColumn('payments', 'subscription_change_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->char('subscription_change_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            });
        }
        if (! Schema::hasColumn('payments', 'provider_preference_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->string('provider_preference_id', 128)->nullable();
            });
        }
        if (! Schema::hasIndex('payments', 'payments_provider_preference_id_unique')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->unique('provider_preference_id', 'payments_provider_preference_id_unique');
            });
        }
        if (! Schema::hasForeignKey('payments', 'payments_company_id_subscription_change_id_foreign')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->foreign(['company_id', 'subscription_change_id'])->references(['company_id', 'id'])->on('subscription_changes')->restrictOnDelete();
            });
        }

        Schema::create('law_contacts', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_unit_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->char('merged_into_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('display_name', 180);
            $table->string('legal_name', 180)->nullable();
            $table->string('contact_type', 32)->default('person');
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('ativo');
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('updated_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'id'], 'law_contacts_company_id_unique');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'law_unit_id'])->references(['company_id', 'id'])->on('law_units')->restrictOnDelete();
            $table->foreign(['company_id', 'merged_into_id'])->references(['company_id', 'id'])->on('law_contacts')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['company_id', 'deleted_at', 'merged_into_id'], 'law_contacts_company_count_idx');
            $table->index(['company_id', 'law_unit_id', 'status', 'display_name'], 'law_contacts_company_unit_name_idx');
        });

        Schema::create('law_usage_alert_states', function (Blueprint $table): void {
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('module_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('personalization_code', 64);
            $table->boolean('over_threshold')->default(false);
            $table->timestamp('updated_at')->nullable();
            $table->primary(['company_id', 'module_id', 'personalization_code'], 'law_usage_alert_state_pk');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('modules')->restrictOnDelete();
        });

        Schema::create('law_notifications', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('user_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('module_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('personalization_code', 64);
            $table->string('title', 160);
            $table->string('message', 500);
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('modules')->restrictOnDelete();
            $table->index(['company_id', 'user_id', 'resolved_at', 'read_at'], 'law_notifications_inbox_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('law_notifications');
        Schema::dropIfExists('law_usage_alert_states');
        Schema::dropIfExists('law_contacts');
        if (Schema::hasForeignKey('payments', 'payments_company_id_subscription_change_id_foreign')) {
            Schema::table('payments', function (Blueprint $table): void { $table->dropForeign('payments_company_id_subscription_change_id_foreign'); });
        }
        if (Schema::hasIndex('payments', 'payments_provider_preference_id_unique')) {
            Schema::table('payments', function (Blueprint $table): void { $table->dropUnique('payments_provider_preference_id_unique'); });
        }
        foreach (['subscription_change_id', 'provider_preference_id'] as $column) {
            if (Schema::hasColumn('payments', $column)) Schema::table('payments', fn (Blueprint $table) => $table->dropColumn($column));
        }
        if (Schema::hasIndex('subscription_changes', 'subscription_changes_company_id_unique')) {
            Schema::table('subscription_changes', function (Blueprint $table): void { $table->dropUnique('subscription_changes_company_id_unique'); });
        }
    }
};
