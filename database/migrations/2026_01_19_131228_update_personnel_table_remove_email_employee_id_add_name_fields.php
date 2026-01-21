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
        // Drop unique indexes if they exist
        try {
            \DB::statement('ALTER TABLE personnel DROP INDEX personnel_email_unique');
        } catch (\Exception $e) {
            // Index doesn't exist, continue
        }
        
        try {
            \DB::statement('ALTER TABLE personnel DROP INDEX personnel_employee_id_unique');
        } catch (\Exception $e) {
            // Index doesn't exist, continue
        }

        Schema::table('personnel', function (Blueprint $table) {
            // Drop email-related columns
            $table->dropColumn(['email', 'email_verified_at']);
            
            // Drop employee_id column
            $table->dropColumn('employee_id');
            
            // Add new name fields
            $table->string('first_name')->after('username');
            $table->string('last_name')->after('first_name');
            $table->string('middle_name')->nullable()->after('last_name');
        });

        // Migrate existing name data to first_name and last_name
        \DB::table('personnel')->get()->each(function ($person) {
            $nameParts = explode(' ', $person->name ?? '', 3);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? ($nameParts[0] ?? '');
            $middleName = $nameParts[2] ?? null;
            
            \DB::table('personnel')
                ->where('id', $person->id)
                ->update([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'middle_name' => $middleName,
                ]);
        });

        // Drop the old name column
        Schema::table('personnel', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            // Add back name column
            $table->string('name')->after('username');
        });

        // Migrate name fields back to name
        \DB::table('personnel')->get()->each(function ($person) {
            $fullName = trim(
                ($person->first_name ?? '') . ' ' .
                ($person->middle_name ?? '') . ' ' .
                ($person->last_name ?? '')
            );
            
            \DB::table('personnel')
                ->where('id', $person->id)
                ->update(['name' => $fullName]);
        });

        Schema::table('personnel', function (Blueprint $table) {
            // Drop new name fields
            $table->dropColumn(['first_name', 'last_name', 'middle_name']);
            
            // Add back email columns
            $table->string('email')->unique()->after('username');
            $table->timestamp('email_verified_at')->nullable()->after('email');
            
            // Add back employee_id
            $table->string('employee_id')->unique()->nullable()->after('department');
        });
    }
};
