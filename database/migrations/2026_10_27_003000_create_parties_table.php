<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer / supplier details. One row per party ledger (Sundry Debtors or
     * Sundry Creditors); balances and credit terms stay on the ledger itself.
     */
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->unique()->constrained('chart_of_accounts')->cascadeOnDelete();
            // regular, composition, unregistered, consumer, overseas, sez
            $table->string('gst_registration_type', 20)->default('unregistered');
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('billing_address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('country')->default('India');
            $table->text('shipping_address')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'gstin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
