<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Costing method per stock item (weighted average or FIFO) and a bill of
     * materials: the components that make bom_quantity units of the item.
     */
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('costing_method', 10)->default('average')->after('purchase_rate');
            $table->decimal('bom_quantity', 14, 3)->nullable()->after('costing_method');
        });

        Schema::create('bom_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete(); // the item made
            $table->foreignId('component_id')->constrained('stock_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3); // for bom_quantity units of the item
            $table->unsignedSmallInteger('line_no')->default(0);
            $table->timestamps();
            $table->unique(['stock_item_id', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_components');
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn(['costing_method', 'bom_quantity']);
        });
    }
};
