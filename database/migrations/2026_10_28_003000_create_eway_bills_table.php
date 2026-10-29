<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * E-way bill details of a sales invoice: the transport (Part B) used to
     * make the portal's bulk-generation JSON, and the e-way bill number,
     * date and validity once the portal has issued it.
     */
    public function up(): void
    {
        Schema::create('eway_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('trans_mode', 5)->default('road'); // road, rail, air, ship
            $table->unsignedSmallInteger('distance_km');
            $table->string('vehicle_no', 15)->nullable();
            $table->char('vehicle_type', 1)->default('R'); // R regular, O over-dimensional cargo
            $table->string('transporter_id', 15)->nullable();
            $table->string('transporter_name', 100)->nullable();
            $table->string('trans_doc_no', 15)->nullable();
            $table->date('trans_doc_date')->nullable();
            $table->string('ewb_no', 12)->nullable();
            $table->date('ewb_date')->nullable();
            $table->date('valid_till')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eway_bills');
    }
};
