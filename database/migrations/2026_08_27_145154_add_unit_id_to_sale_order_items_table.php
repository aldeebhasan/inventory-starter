<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sale_order_items', 'unit_id')) {
            Schema::table('sale_order_items', function (Blueprint $table) {
                $table->foreignId('unit_id')->nullable()->after('product_id')->constrained('units')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('sale_order_items', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};
