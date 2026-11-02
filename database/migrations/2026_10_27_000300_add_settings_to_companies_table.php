<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('mailing_name')->nullable()->after('legal_name');
            $table->string('city', 100)->nullable()->after('address');
            $table->string('pincode', 10)->nullable()->after('state');
            $table->string('country', 100)->default('India')->after('pincode');
            $table->string('website')->nullable()->after('phone');
            $table->string('cin', 21)->nullable()->after('pan');
            $table->string('tan', 10)->nullable()->after('cin');
            $table->date('books_begin_date')->nullable()->after('financial_year');
            $table->string('logo_path')->nullable();
            $table->json('settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'mailing_name', 'city', 'pincode', 'country', 'website', 'cin', 'tan',
                'books_begin_date', 'logo_path', 'settings',
            ]);
        });
    }
};
