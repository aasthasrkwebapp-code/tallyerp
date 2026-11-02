<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        // Existing companies: give their creator an owner membership.
        $ownerRoleId = DB::table('roles')->where('name', 'owner')->whereNull('company_id')->value('id');

        if (! $ownerRoleId) {
            $ownerRoleId = DB::table('roles')->insertGetId([
                'name' => 'owner',
                'description' => 'Full access to all modules and settings',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('companies')->get(['id', 'user_id']) as $company) {
            DB::table('company_user')->insert([
                'company_id' => $company->id,
                'user_id' => $company->user_id,
                'role_id' => $ownerRoleId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
