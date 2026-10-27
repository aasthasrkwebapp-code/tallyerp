<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Group codes were globally unique; they only need to be unique per company.
        Schema::table('account_groups', function (Blueprint $table) {
            $table->dropUnique('account_groups_code_unique');
        });

        Schema::table('account_groups', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('company_id')->constrained('account_groups')->restrictOnDelete();
            $table->string('system_key', 50)->nullable()->after('code');
            $table->string('nature', 20)->default('asset')->after('system_key'); // asset, liability, income, expense
            $table->boolean('affects_gross_profit')->default(false)->after('nature');
            $table->boolean('is_system')->default(false)->after('is_primary');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_system');
            $table->string('description')->nullable();

            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'system_key']);
            $table->index(['company_id', 'nature']);
        });
    }

    public function down(): void
    {
        Schema::table('account_groups', function (Blueprint $table) {
            // MySQL needs an index for the company foreign key once the composite ones are gone.
            $table->index('company_id');
            $table->dropUnique(['company_id', 'name']);
            $table->dropUnique(['company_id', 'code']);
            $table->dropUnique(['company_id', 'system_key']);
            $table->dropIndex(['company_id', 'nature']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['system_key', 'nature', 'affects_gross_profit', 'is_system', 'sort_order', 'description']);
        });

        Schema::table('account_groups', function (Blueprint $table) {
            $table->unique('code');
        });
    }
};
