<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Whether unused leave of a type moves to the next financial year, and the most days that can. */
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('carry_forward')->default(false)->after('annual_days');
            $table->decimal('max_carry_forward', 5, 1)->nullable()->after('carry_forward');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['carry_forward', 'max_carry_forward']);
        });
    }
};
