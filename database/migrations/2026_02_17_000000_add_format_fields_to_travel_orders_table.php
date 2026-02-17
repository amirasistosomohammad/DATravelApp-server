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
        Schema::table('travel_orders', function (Blueprint $table) {
            // Fields needed to match the official TO form format
            $table->string('official_station', 255)->nullable()->after('destination');
            $table->string('per_diems_note', 255)->nullable()->after('per_diems_expenses'); // e.g. "800/diem"
            $table->string('assistant_or_laborers_allowed', 255)->nullable()->after('per_diems_note'); // e.g. "N/A"
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('travel_orders', function (Blueprint $table) {
            $table->dropColumn(['official_station', 'per_diems_note', 'assistant_or_laborers_allowed']);
        });
    }
};

