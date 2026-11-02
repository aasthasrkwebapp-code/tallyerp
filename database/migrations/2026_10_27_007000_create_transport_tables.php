<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transport business: vehicles, drivers, routes, trips and vehicle/trip
     * expenses. Freight is billed through a sales invoice and expenses post
     * vouchers, so the accounts stay the single source of the figures.
     */
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20); // registration number
            $table->string('type', 50)->nullable(); // truck, trailer, tempo…
            $table->string('make_model', 100)->nullable();
            $table->decimal('capacity_tonnes', 8, 2)->nullable();
            $table->string('ownership', 10)->default('own'); // own, hired
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('phone', 20)->nullable();
            $table->string('licence_number', 30)->nullable();
            $table->date('licence_expiry')->nullable();
            $table->foreignId('account_id')->unique()->constrained('chart_of_accounts')->restrictOnDelete(); // advances ledger
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('transport_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('origin', 100);
            $table->string('destination', 100);
            $table->decimal('distance_km', 8, 1)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('transport_route_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('party_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete(); // customer
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('origin', 100);
            $table->string('destination', 100);
            $table->string('lr_number', 50)->nullable(); // lorry receipt / consignment note
            $table->string('material', 150)->nullable();
            $table->decimal('weight_tonnes', 10, 3)->nullable();
            $table->decimal('freight_amount', 14, 2)->default(0); // agreed freight
            $table->unsignedInteger('start_km')->nullable();
            $table->unsignedInteger('end_km')->nullable();
            $table->string('status', 20)->default('open'); // open, completed, cancelled
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->text('narration')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'start_date']);
        });

        Schema::create('transport_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('category', 20);
            $table->string('description', 255)->nullable();
            $table->decimal('quantity', 10, 2)->nullable(); // litres of fuel
            $table->decimal('amount', 14, 2);
            $table->foreignId('expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('paid_account_id')->constrained('chart_of_accounts')->restrictOnDelete(); // cash, bank, driver or supplier
            $table->foreignId('voucher_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('posted'); // posted, cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'date']);
        });

        // Adds the new Transport role to existing installations (the seeder is idempotent).
        if (\Illuminate\Support\Facades\DB::table('permissions')->exists()) {
            (new \Database\Seeders\RolesSeeder)->run();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_expenses');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('transport_routes');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vehicles');
    }
};
