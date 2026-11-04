<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->boolean('track_batches')->default(false)->after('selling_price');
        });

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->string('batch_no', 40);
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            $table->unique(['stock_item_id', 'batch_no']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_batch_id')->nullable()->after('stock_item_id')->constrained()->nullOnDelete();
        });

        Schema::table('voucher_gst_lines', function (Blueprint $table) {
            $table->string('batch_no', 40)->nullable()->after('stock_item_id');
            $table->date('expiry_date')->nullable()->after('batch_no');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_gst_lines', function (Blueprint $table) {
            $table->dropColumn(['batch_no', 'expiry_date']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_batch_id');
        });
        Schema::dropIfExists('stock_batches');
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn('track_batches');
        });
    }
};
