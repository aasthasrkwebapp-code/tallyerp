<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bill of materials: making `output_quantity` of the finished item uses these components.
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('output_quantity', 15, 3)->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('bom_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->timestamps();
        });

        // A production run: components out, finished goods in.
        Schema::create('productions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bom_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->date('date');
            $table->decimal('quantity', 15, 3);
            $table->decimal('material_cost', 15, 2);
            $table->decimal('other_cost', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('production_id')->nullable()->after('voucher_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_id');
        });
        Schema::dropIfExists('productions');
        Schema::dropIfExists('bom_components');
        Schema::dropIfExists('boms');
    }
};
