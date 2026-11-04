<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill role_id from the legacy string column, then drop it so
        // $user->role always resolves to the Role relationship.
        if (Schema::hasColumn('users', 'role')) {
            foreach (DB::table('roles')->get(['id', 'name']) as $role) {
                DB::table('users')
                    ->whereNull('role_id')
                    ->where('role', $role->name)
                    ->update(['role_id' => $role->id]);
            }

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }

        DB::table('users')
            ->whereNotNull('role_id')
            ->whereNotIn('role_id', DB::table('roles')->select('id'))
            ->update(['role_id' => null]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
        });

        Schema::table('account_groups', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['company_id', 'code']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['gstin']);
            $table->unique(['user_id', 'gstin']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'gstin']);
            $table->unique('gstin');
        });

        Schema::table('account_groups', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'code']);
            $table->unique('code');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->string('role')->default('user')->after('password');
        });
    }
};
