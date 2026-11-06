<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Party credit terms: bills fall due after credit_days; warn above credit_limit.
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('credit_days')->nullable()->after('price_list_id');
            $table->decimal('credit_limit', 15, 2)->nullable()->after('credit_days');
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn(['credit_days', 'credit_limit']);
        });
    }
};
