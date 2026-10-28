<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The IRN, acknowledgement and signed QR code the e-invoice portal (IRP) returns for a sales invoice or credit note. */
    public function up(): void
    {
        Schema::create('e_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('irn', 64);
            $table->string('ack_no', 20);
            $table->dateTime('ack_date');
            $table->text('signed_qr')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'irn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoices');
    }
};
