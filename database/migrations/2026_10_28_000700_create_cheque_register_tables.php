<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cheque register: instrument details on bank vouchers, cheque books
     * (leaf ranges per bank, with the print layout) and spoilt leaves.
     * Whether a cheque has cleared comes from the bank line's bank date.
     */
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('instrument_type', 10)->nullable()->after('reference_date'); // cheque, dd, neft, rtgs, imps, upi
            $table->string('instrument_number', 30)->nullable()->after('instrument_type');
            $table->date('instrument_date')->nullable()->after('instrument_number');
            $table->string('payee_name', 150)->nullable()->after('instrument_date');
            $table->foreignId('instrument_account_id')->nullable()->after('payee_name')->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('instrument_direction', 10)->nullable()->after('instrument_account_id'); // issued, received
            $table->index(['company_id', 'instrument_account_id', 'instrument_number'], 'vouchers_instrument_index');
        });

        Schema::create('cheque_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('from_number');
            $table->unsignedInteger('to_number');
            $table->unsignedTinyInteger('digits')->default(6);
            $table->boolean('is_active')->default(true);
            $table->json('layout')->nullable(); // print positions in mm
            $table->timestamps();
            $table->index(['company_id', 'account_id']);
        });

        Schema::create('spoilt_cheques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cheque_book_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->date('date');
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cheque_book_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spoilt_cheques');
        Schema::dropIfExists('cheque_books');
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex('vouchers_instrument_index');
            $table->dropConstrainedForeignId('instrument_account_id');
            $table->dropColumn(['instrument_type', 'instrument_number', 'instrument_date', 'payee_name', 'instrument_direction']);
        });
    }
};
