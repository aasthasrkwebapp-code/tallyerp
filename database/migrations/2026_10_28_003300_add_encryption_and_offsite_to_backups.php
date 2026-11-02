<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // The backup passphrase, encrypted with the application key. Not part of a backup.
            $table->text('backup_secret')->nullable();
        });
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->boolean('encrypted')->default(false)->after('sha256');
            $table->text('secret')->nullable()->after('encrypted');
            $table->string('offsite_path')->nullable()->after('secret');
            $table->timestamp('offsite_at')->nullable()->after('offsite_path');
            $table->string('offsite_error', 500)->nullable()->after('offsite_at');
        });
    }

    public function down(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->dropColumn(['encrypted', 'secret', 'offsite_path', 'offsite_at', 'offsite_error']);
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('backup_secret');
        });
    }
};
