<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Co-products on a bill of materials take a percentage of the manufacturing cost. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bom_components', function (Blueprint $table) {
            $table->decimal('cost_share', 5, 2)->nullable()->after('rate'); // co-products: % of the cost
        });
    }

    public function down(): void
    {
        Schema::table('bom_components', function (Blueprint $table) {
            $table->dropColumn('cost_share');
        });
    }
};
