<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Storage locations. Stock with no godown is in the "Main location".
        Schema::create('godowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        // Stock journal: moving stock from one godown to another.
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->date('date');
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('godown_id')->nullable()->after('stock_batch_id')->constrained()->nullOnDelete();
            $table->foreignId('stock_transfer_id')->nullable()->after('production_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_transfer_id');
            $table->dropConstrainedForeignId('godown_id');
        });
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('godowns');
    }
};
