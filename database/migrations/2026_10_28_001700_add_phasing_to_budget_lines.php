<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Month-by-month amounts of a budget line; null means spread evenly by days. */
    public function up(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->json('phasing')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropColumn('phasing');
        });
    }
};
