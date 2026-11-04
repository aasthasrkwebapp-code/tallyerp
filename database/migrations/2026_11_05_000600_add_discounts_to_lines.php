<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Line value before discount, and the discount % taken off before GST.
        foreach (['voucher_gst_lines', 'business_document_lines'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->decimal('gross_amount', 15, 2)->nullable()->after('item_rate');
                $table->decimal('discount_percent', 5, 2)->default(0)->after('gross_amount');
            });
        }
    }

    public function down(): void
    {
        foreach (['voucher_gst_lines', 'business_document_lines'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['gross_amount', 'discount_percent']);
            });
        }
    }
};
