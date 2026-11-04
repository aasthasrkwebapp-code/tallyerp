<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Estimates, orders and challans: documents that don't post to the books
        // until they are converted into an invoice.
        Schema::create('business_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->unsignedInteger('number');
            $table->unsignedSmallInteger('fy_start_year');
            $table->date('date');
            $table->date('valid_until')->nullable();
            $table->foreignId('party_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('place_of_supply', 2)->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('open'); // open | converted | cancelled
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'type', 'fy_start_year', 'number']);
        });

        Schema::create('business_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->string('hsn_sac', 10)->nullable();
            $table->decimal('quantity', 15, 3)->nullable();
            $table->decimal('item_rate', 15, 2)->nullable();
            $table->decimal('taxable', 15, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('tax', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_lines');
        Schema::dropIfExists('business_documents');
    }
};
