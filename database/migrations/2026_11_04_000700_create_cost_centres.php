<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('cost_centres')->nullOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::table('voucher_entries', function (Blueprint $table) {
            $table->foreignId('cost_centre_id')->nullable()->after('account_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voucher_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_centre_id');
        });

        Schema::dropIfExists('cost_centres');
    }
};
