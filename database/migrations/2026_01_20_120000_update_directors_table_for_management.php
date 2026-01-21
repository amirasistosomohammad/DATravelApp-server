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
        Schema::table('directors', function (Blueprint $table) {
            $table->string('first_name', 255)->nullable()->after('name');
            $table->string('middle_name', 255)->nullable()->after('first_name');
            $table->string('last_name', 255)->nullable()->after('middle_name');
            $table->string('phone', 20)->nullable()->after('last_name');
            $table->text('reason_for_deactivation')->nullable()->after('is_active');
            $table->string('avatar_path')->nullable()->after('is_active');
        });

        Schema::table('directors', function (Blueprint $table) {
            $table->string('email', 255)->nullable()->change();
            $table->string('department', 255)->nullable()->default(null)->change();
            $table->string('position', 255)->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('directors', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'middle_name',
                'last_name',
                'phone',
                'reason_for_deactivation',
                'avatar_path',
            ]);
        });

        Schema::table('directors', function (Blueprint $table) {
            $table->string('email', 255)->nullable(false)->change();
            $table->string('department', 255)->nullable(false)->default('Department of Agriculture')->change();
            $table->string('position', 255)->nullable(false)->default('Director III')->change();
        });
    }
};

