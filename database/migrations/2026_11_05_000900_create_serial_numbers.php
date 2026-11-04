<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->boolean('track_serials')->default(false)->after('track_batches');
        });

        // One row per unit. In stock while out_voucher_id is null.
        Schema::create('serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->string('serial', 60);
            // Deleting the purchase removes the unit; deleting the sale puts it back in stock.
            $table->foreignId('in_voucher_id')->nullable()->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('out_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->timestamps();

            $table->unique(['stock_item_id', 'serial']);
            $table->index(['company_id', 'serial']);
        });

        Schema::table('voucher_gst_lines', function (Blueprint $table) {
            $table->text('serial_numbers')->nullable()->after('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_gst_lines', function (Blueprint $table) {
            $table->dropColumn('serial_numbers');
        });
        Schema::dropIfExists('serial_numbers');
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn('track_serials');
        });
    }
};
