<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
        });

        Schema::create('product_addon', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_addon');

        Schema::table('addons', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }
};
