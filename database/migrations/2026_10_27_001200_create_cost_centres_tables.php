<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('allocate_revenue')->default(true);
            $table->boolean('allocate_non_revenue')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('cost_centres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centres');
        Schema::dropIfExists('cost_categories');
    }
};
