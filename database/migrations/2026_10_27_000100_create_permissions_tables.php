<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('module', 50)->index();
            $table->string('action', 50);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        // Roles can be system-wide templates (company_id null) or company-specific.
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_name_unique');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->boolean('is_system')->default(false)->after('description');
            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // Drop the foreign key first: MySQL will not drop the index it relies on.
            $table->dropForeign(['company_id']);
            $table->dropUnique(['company_id', 'name']);
            $table->dropColumn('company_id');
            $table->dropColumn('is_system');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->unique('name');
        });

        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
    }
};
