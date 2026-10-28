<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The bank's own id for a statement line (OFX FITID, MT940 reference), so a file can be imported again safely. */
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->string('external_id', 150)->nullable()->after('deposit');
            $table->index(['account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
