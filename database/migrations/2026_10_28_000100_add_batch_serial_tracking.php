<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch and serial number tracking.
     *
     * Like stock quantities, batch and serial balances are not stored: they are
     * read from the batch number, dates and serial numbers kept on the
     * invoice items and stock journal lines that move the stock. Serials are
     * stored one per line of text, already normalised.
     */
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('tracking', 10)->default('none')->after('reorder_level'); // none, batch, serial
            $table->boolean('track_expiry')->default(false)->after('tracking');
            $table->string('opening_batch_no', 50)->nullable()->after('opening_godown_id');
            $table->date('opening_mfg_date')->nullable()->after('opening_batch_no');
            $table->date('opening_expiry_date')->nullable()->after('opening_mfg_date');
            $table->text('opening_serials')->nullable()->after('opening_expiry_date');
        });

        foreach (['invoice_items' => 'godown_id', 'stock_journal_lines' => 'value'] as $name => $after) {
            Schema::table($name, function (Blueprint $table) use ($after) {
                $table->string('batch_no', 50)->nullable()->after($after);
                $table->date('mfg_date')->nullable()->after('batch_no');
                $table->date('expiry_date')->nullable()->after('mfg_date');
                $table->text('serials')->nullable()->after('expiry_date');
            });
        }
    }

    public function down(): void
    {
        foreach (['invoice_items', 'stock_journal_lines'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['batch_no', 'mfg_date', 'expiry_date', 'serials']));
        }
        Schema::table('stock_items', fn (Blueprint $table) => $table->dropColumn([
            'tracking', 'track_expiry', 'opening_batch_no', 'opening_mfg_date', 'opening_expiry_date', 'opening_serials',
        ]));
    }
};
