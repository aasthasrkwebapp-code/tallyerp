<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('barcode', 48)->nullable()->after('name');
            $table->decimal('selling_price', 15, 2)->nullable()->after('opening_rate');
            $table->unique(['company_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'barcode']);
            $table->dropColumn(['barcode', 'selling_price']);
        });
    }
};
