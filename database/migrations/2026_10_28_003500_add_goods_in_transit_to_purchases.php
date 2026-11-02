<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods in transit on purchases: a purchase bill booked before the goods arrive puts its
 * stock in the transit godown, bound for the godown chosen on each line. Receipts and
 * shortages against it are stock journals linked to the bill and its lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('in_transit')->default(false)->after('reverse_charge');
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('transit_to_godown_id')->nullable()->after('godown_id')->constrained('godowns')->nullOnDelete();
        });
        Schema::table('stock_journals', function (Blueprint $table) {
            $table->foreignId('dispatch_invoice_id')->nullable()->after('dispatch_id')->constrained('invoices')->nullOnDelete();
        });
        Schema::table('stock_journal_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('transit_invoice_item_id')->nullable()->after('transit_line_id');
            $table->index('transit_invoice_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_journal_lines', function (Blueprint $table) {
            $table->dropIndex(['transit_invoice_item_id']);
            $table->dropColumn('transit_invoice_item_id');
        });
        Schema::table('stock_journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dispatch_invoice_id');
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transit_to_godown_id');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('in_transit');
        });
    }
};
