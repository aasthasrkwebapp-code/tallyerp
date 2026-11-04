<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('address');
            $table->string('pincode', 6)->nullable()->after('city');
        });

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->after('state_code');
            $table->string('city', 100)->nullable()->after('address');
            $table->string('pincode', 6)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn(['address', 'city', 'pincode']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['city', 'pincode']);
        });
    }
};
