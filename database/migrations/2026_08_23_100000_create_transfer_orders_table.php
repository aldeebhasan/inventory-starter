<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->unsignedBigInteger('from_location_id')->index();
            $table->foreign('from_location_id')->references('id')->on('inventorix_locations')->cascadeOnDelete();
            $table->unsignedBigInteger('to_location_id')->index();
            $table->foreign('to_location_id')->references('id')->on('inventorix_locations')->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_orders');
    }
};
