<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Warn when closing stock falls to this quantity or below.
        Schema::table('stock_items', function (Blueprint $table) {
            $table->decimal('reorder_level', 15, 3)->nullable()->after('selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn('reorder_level');
        });
    }
};
