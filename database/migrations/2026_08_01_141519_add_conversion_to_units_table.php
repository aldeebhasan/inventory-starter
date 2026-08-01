<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('base_unit_id')->nullable()->after('abbreviation')->constrained('units')->nullOnDelete();
            $table->decimal('conversion_factor', 15, 6)->default(1)->after('base_unit_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('base_unit_id');
            $table->dropColumn('conversion_factor');
        });
    }
};
