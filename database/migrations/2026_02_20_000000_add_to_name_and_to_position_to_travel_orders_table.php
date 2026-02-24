<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Name and Position/Designation on the TO (person the TO is for).
     * When a coworker creates a TO on behalf of another person, these hold the beneficiary's name/position.
     */
    public function up(): void
    {
        Schema::table('travel_orders', function (Blueprint $table) {
            $table->string('to_name', 255)->nullable()->after('personnel_id');
            $table->string('to_position', 255)->nullable()->after('to_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('travel_orders', function (Blueprint $table) {
            $table->dropColumn(['to_name', 'to_position']);
        });
    }
};
