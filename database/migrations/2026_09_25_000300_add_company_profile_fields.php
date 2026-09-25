<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('display_name', 255)->nullable()->after('legal_name');
            $table->string('contact_email', 255)->nullable()->after('display_name');
            $table->string('contact_phone', 30)->nullable()->after('contact_email');
            $table->string('website', 255)->nullable()->after('contact_phone');
            $table->string('address_postal_code', 9)->nullable()->after('website');
            $table->string('address_street', 255)->nullable()->after('address_postal_code');
            $table->string('address_number', 30)->nullable()->after('address_street');
            $table->string('address_complement', 100)->nullable()->after('address_number');
            $table->string('address_district', 100)->nullable()->after('address_complement');
            $table->string('address_city', 100)->nullable()->after('address_district');
            $table->char('address_state', 2)->nullable()->after('address_city');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'display_name', 'contact_email', 'contact_phone', 'website', 'address_postal_code',
                'address_street', 'address_number', 'address_complement', 'address_district', 'address_city', 'address_state',
            ]);
        });
    }
};
