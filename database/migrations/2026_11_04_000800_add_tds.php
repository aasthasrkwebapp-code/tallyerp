<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('pan', 10)->nullable()->after('gst_rate');
            $table->string('tds_section', 10)->nullable()->after('pan');
        });

        Schema::create('tds_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('section', 10);
            $table->decimal('base_amount', 15, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('tds_amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tds_deductions');

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn(['pan', 'tds_section']);
        });
    }
};
