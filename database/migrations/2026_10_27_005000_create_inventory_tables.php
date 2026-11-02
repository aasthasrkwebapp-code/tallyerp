<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventory masters and stock journals.
     *
     * Stock movements are not stored separately: they are read from posted
     * invoices (items with a stock item) and posted stock journals, so stock
     * can never disagree with the documents that moved it.
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 20);
            $table->string('name', 100);
            $table->unsignedTinyInteger('decimal_places')->default(0);
            $table->string('uqc', 10)->nullable(); // GST unit quantity code, e.g. NOS, KGS
            $table->timestamps();
            $table->unique(['company_id', 'symbol']);
        });

        Schema::create('stock_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('stock_groups')->restrictOnDelete();
            $table->string('name', 150);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('stock_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('godowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('address', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('alias', 150)->nullable();
            $table->string('code', 50)->nullable(); // SKU / part number
            $table->foreignId('stock_group_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stock_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('hsn_sac', 10)->nullable();
            $table->decimal('gst_rate', 5, 2)->nullable();
            $table->foreignId('sales_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('purchase_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->decimal('sales_rate', 18, 2)->nullable();
            $table->decimal('purchase_rate', 18, 2)->nullable();
            $table->decimal('opening_quantity', 15, 3)->default(0);
            $table->decimal('opening_value', 18, 2)->default(0);
            $table->foreignId('opening_godown_id')->nullable()->constrained('godowns')->restrictOnDelete();
            $table->decimal('reorder_level', 15, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'code']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('stock_item_id')->nullable()->after('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('godown_id')->nullable()->after('stock_item_id')->constrained()->restrictOnDelete();
            $table->index(['stock_item_id']);
        });

        Schema::create('stock_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->date('date');
            $table->string('purpose', 20)->default('adjustment'); // adjustment, transfer, production
            $table->string('status', 20)->default('posted'); // posted, cancelled
            $table->text('narration')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'date']);
        });

        Schema::create('stock_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_journal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('godown_id')->constrained()->restrictOnDelete();
            $table->char('direction', 3); // in, out
            $table->decimal('quantity', 15, 3);
            $table->decimal('rate', 18, 2)->default(0);
            $table->decimal('value', 18, 2)->default(0);
            $table->timestamps();
            $table->index(['stock_item_id']);
        });

        // Existing companies get the same defaults as new ones.
        foreach (\Illuminate\Support\Facades\DB::table('companies')->pluck('id') as $companyId) {
            \App\Services\Setup\DefaultInventory::seedRows((int) $companyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_journal_lines');
        Schema::dropIfExists('stock_journals');
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign(['stock_item_id']);
            $table->dropForeign(['godown_id']);
            $table->dropIndex(['stock_item_id']);
            $table->dropColumn(['stock_item_id', 'godown_id']);
        });
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('godowns');
        Schema::dropIfExists('stock_categories');
        Schema::dropIfExists('stock_groups');
        Schema::dropIfExists('units');
    }
};
