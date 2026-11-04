<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 20);
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('decimals')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'symbol']);
        });

        Schema::create('stock_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('stock_groups')->nullOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('hsn_sac', 10)->nullable();
            $table->decimal('gst_rate', 5, 2)->nullable();
            $table->decimal('opening_quantity', 15, 3)->default(0);
            $table->decimal('opening_rate', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('quantity_in', 15, 3)->default(0);
            $table->decimal('quantity_out', 15, 3)->default(0);
            $table->decimal('rate', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index(['stock_item_id', 'date']);
        });

        Schema::table('voucher_gst_lines', function (Blueprint $table) {
            $table->foreignId('stock_item_id')->nullable()->after('account_id')
                ->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3)->nullable()->after('hsn_sac');
            $table->decimal('item_rate', 15, 2)->nullable()->after('quantity');
        });

        // Give companies created before this migration their default units.
        foreach (\App\Models\Company::all() as $company) {
            $company->createDefaultUnits();
        }
    }

    public function down(): void
    {
        Schema::table('voucher_gst_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_item_id');
            $table->dropColumn(['quantity', 'item_rate']);
        });

        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('stock_groups');
        Schema::dropIfExists('units');
    }
};
