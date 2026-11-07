<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->json('definition');
            $table->timestamps();
            $table->softDeletes();

            // The list query: my reports or shared ones.
            $table->index(['is_shared', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
    }
};
