<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods in transit: a dispatch moves stock into the company's transit godown, bound for
     * transit_to_godown_id. Receipts and shortages against it point back with dispatch_id, and
     * each of their lines names the dispatch line it settles.
     */
    public function up(): void
    {
        Schema::table('godowns', function (Blueprint $table) {
            $table->boolean('is_transit')->default(false)->after('is_default');
        });
        Schema::table('stock_journals', function (Blueprint $table) {
            $table->foreignId('transit_to_godown_id')->nullable()->after('purpose')->constrained('godowns')->nullOnDelete();
            $table->foreignId('dispatch_id')->nullable()->after('transit_to_godown_id')->constrained('stock_journals')->nullOnDelete();
        });
        Schema::table('stock_journal_lines', function (Blueprint $table) {
            $table->foreignId('transit_line_id')->nullable()->after('stock_journal_id')->constrained('stock_journal_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_journal_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transit_line_id');
        });
        Schema::table('stock_journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dispatch_id');
            $table->dropConstrainedForeignId('transit_to_godown_id');
        });
        Schema::table('godowns', function (Blueprint $table) {
            $table->dropColumn('is_transit');
        });
    }
};
