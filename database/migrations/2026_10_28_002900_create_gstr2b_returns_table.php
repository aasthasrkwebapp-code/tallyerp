<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** GSTR-2B statements downloaded from the GST portal, kept for matching against the purchase books. */
    public function up(): void
    {
        Schema::create('gstr2b_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7); // YYYY-MM
            $table->string('gstin', 15);
            $table->string('generated_on', 20)->nullable();
            $table->unsignedInteger('document_count')->default(0);
            $table->longText('data'); // the portal's JSON
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gstr2b_returns');
    }
};
