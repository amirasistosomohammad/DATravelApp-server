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
        Schema::table('time_logs', function (Blueprint $table) {
            $table->foreignId('director_id')
                ->nullable()
                ->after('personnel_id')
                ->constrained('directors')
                ->nullOnDelete();
        });

        // Make personnel_id nullable to allow director time logs.
        DB::statement('ALTER TABLE time_logs MODIFY personnel_id BIGINT UNSIGNED NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('time_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('director_id');
        });

        DB::statement('ALTER TABLE time_logs MODIFY personnel_id BIGINT UNSIGNED NOT NULL');
    }
};

