<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Revision numbers on quotations and orders, and a copy of each earlier version. */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(0)->after('number');
        });
        Schema::create('order_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('data');
            $table->decimal('grand_total', 15, 2);
            $table->string('note')->nullable();
            $table->foreignId('revised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['order_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_revisions');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
