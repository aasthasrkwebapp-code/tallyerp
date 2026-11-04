<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            // Party ledgers (debtors / creditors)
            $table->string('gstin', 15)->nullable()->after('is_active');
            $table->string('state_code', 2)->nullable()->after('gstin');
            // Sales / purchase ledgers
            $table->string('hsn_sac', 10)->nullable()->after('state_code');
            $table->decimal('gst_rate', 5, 2)->nullable()->after('hsn_sac');
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('party_account_id')->nullable()->after('amount')
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('place_of_supply', 2)->nullable()->after('party_account_id');
        });

        Schema::create('voucher_gst_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->string('hsn_sac', 10)->nullable();
            $table->decimal('taxable', 15, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('cgst', 15, 2)->default(0);
            $table->decimal('sgst', 15, 2)->default(0);
            $table->decimal('igst', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_gst_lines');

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('party_account_id');
            $table->dropColumn('place_of_supply');
        });

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn(['gstin', 'state_code', 'hsn_sac', 'gst_rate']);
        });
    }
};
