<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vehicle documents (registration, insurance, permits, fitness, PUC, road tax) with their
     * expiry dates, and a log of the expiry reminders e-mailed so each is sent once.
     */
    public function up(): void
    {
        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('number', 60)->nullable();
            $table->string('issuer', 100)->nullable(); // insurer, RTO
            $table->date('issued_on')->nullable();
            $table->date('expires_on');
            $table->decimal('amount', 18, 2)->nullable(); // premium or fee paid
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('expires_on');
        });

        Schema::create('document_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 20); // vehicle_document, driver
            $table->unsignedBigInteger('subject_id');
            $table->date('expires_on');
            $table->string('kind', 10); // due, expired
            $table->timestamp('sent_at');
            $table->unique(['subject_type', 'subject_id', 'expires_on', 'kind'], 'document_reminders_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reminders');
        Schema::dropIfExists('vehicle_documents');
    }
};
