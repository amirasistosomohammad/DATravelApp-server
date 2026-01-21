<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Make department nullable and remove the default.
        // Use raw SQL to avoid requiring doctrine/dbal.
        DB::statement("ALTER TABLE personnel MODIFY department VARCHAR(255) NULL DEFAULT NULL");

        // Optional: convert legacy default values to NULL so UI can show "Not specified".
        DB::table('personnel')
            ->where('department', 'Department of Agriculture')
            ->update(['department' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore the original NOT NULL default behavior.
        DB::statement("ALTER TABLE personnel MODIFY department VARCHAR(255) NOT NULL DEFAULT 'Department of Agriculture'");

        // Restore nulls to default string to keep data consistent.
        DB::table('personnel')
            ->whereNull('department')
            ->update(['department' => 'Department of Agriculture']);
    }
};
