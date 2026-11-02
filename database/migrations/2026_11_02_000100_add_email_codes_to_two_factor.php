<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor sign-in by a code e-mailed at each sign-in, as well as by an
 * authenticator app. The latest code is kept hashed until it is used or expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_method', 10)->default('app')->after('two_factor_last_step'); // app, email
            $table->string('two_factor_email_code')->nullable()->after('two_factor_method');
            $table->timestamp('two_factor_email_expires_at')->nullable()->after('two_factor_email_code');
            $table->timestamp('two_factor_email_sent_at')->nullable()->after('two_factor_email_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_method', 'two_factor_email_code', 'two_factor_email_expires_at', 'two_factor_email_sent_at']);
        });
    }
};
