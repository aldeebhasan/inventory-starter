<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_orders', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('sale_order_id')
                ->constrained('purchase_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
