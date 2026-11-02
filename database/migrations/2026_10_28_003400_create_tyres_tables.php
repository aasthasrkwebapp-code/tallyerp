<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tyres by serial number, and each time one is fitted to a vehicle: position, date and
     * odometer on and off, so the kilometres run and the cost per kilometre can be worked out.
     */
    public function up(): void
    {
        Schema::create('tyres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('serial_no', 40);
            $table->string('brand', 60)->nullable();
            $table->string('size', 40)->nullable();
            $table->date('purchased_on')->nullable();
            $table->decimal('cost', 18, 2)->default(0);
            $table->string('supplier', 100)->nullable();
            $table->unsignedInteger('expected_km')->nullable();
            $table->unsignedSmallInteger('retreads')->default(0);
            $table->decimal('retread_cost', 18, 2)->default(0);
            $table->string('status', 10)->default('stock'); // stock, fitted, scrapped
            $table->date('scrapped_on')->nullable();
            $table->string('scrap_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'serial_no']);
        });

        Schema::create('tyre_fitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tyre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('position', 12);
            $table->date('fitted_on');
            $table->unsignedInteger('fitted_km');
            $table->date('removed_on')->nullable();
            $table->unsignedInteger('removed_km')->nullable();
            $table->string('removal_reason', 20)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tyre_fitments');
        Schema::dropIfExists('tyres');
    }
};
