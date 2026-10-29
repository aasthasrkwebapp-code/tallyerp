<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * By-products and scrap in a bill of materials: lines that come out of manufacturing
     * alongside the main item, valued at a rate that is taken off the main item's cost.
     */
    public function up(): void
    {
        Schema::table('bom_components', function (Blueprint $table) {
            $table->string('kind', 12)->default('component')->after('component_id'); // component, by_product, scrap
            $table->decimal('rate', 18, 2)->nullable()->after('quantity'); // value per unit of a by-product or scrap
        });
    }

    public function down(): void
    {
        Schema::table('bom_components', function (Blueprint $table) {
            $table->dropColumn(['kind', 'rate']);
        });
    }
};
