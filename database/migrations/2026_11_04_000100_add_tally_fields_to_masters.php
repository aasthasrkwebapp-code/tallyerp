<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_groups', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('company_id')
                ->constrained('account_groups')->nullOnDelete();
            // assets | liabilities | income | expenses
            $table->string('nature', 20)->default('assets')->after('code');
        });

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('opening_balance_type', 2)->default('dr')->after('opening_balance');
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('opening_balance_type');
        });

        Schema::table('account_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('nature');
        });
    }
};
