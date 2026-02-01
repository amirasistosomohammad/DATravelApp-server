<?php

namespace Database\Seeders;

use App\Models\Personnel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PersonnelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing to avoid unique constraint issues when reseeding
        // Comment this out if you don't want truncation in non-local envs.
        if (app()->environment('local')) {
            // MySQL blocks TRUNCATE when FK constraints exist, even if empty.
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::table('personnel')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // Long names for testing responsiveness
        $firstNames = [
            'Christopher', 'Alexander', 'Benjamin', 'Theodore', 'Sebastian',
            'Maximilian', 'Nathaniel', 'Jonathan', 'Frederick', 'Montgomery',
            'Guadalupe', 'Isabella', 'Alexandria', 'Penelope', 'Theodora',
            'Victoria', 'Gabriella', 'Valentina', 'Seraphina', 'Evangeline',
            'Jose', 'Maria', 'Juan', 'Carlos', 'Miguel',
            'Fernando', 'Rodrigo', 'Alejandro', 'Ricardo', 'Eduardo',
            'Consuelo', 'Dolores', 'Esperanza', 'Rosario', 'Mercedes',
            'Guadalupe', 'Concepcion', 'Soledad', 'Carmen', 'Dolores'
        ];

        $lastNames = [
            'Montgomery', 'Fitzgerald', 'Harrington', 'Worthington', 'Cunningham',
            'Abercrombie', 'Bartholomew', 'Christensen', 'Davenport', 'Ellington',
            'Rodriguez', 'Gonzalez', 'Martinez', 'Fernandez', 'Lopez',
            'Sanchez', 'Ramirez', 'Torres', 'Flores', 'Rivera',
            'Santos', 'Reyes', 'Cruz', 'Garcia', 'Ramos',
            'Villanueva', 'Bautista', 'Manalang', 'Macapagal', 'Magtanggol',
            'De la Cruz', 'De los Santos', 'De Guzman', 'De Vera', 'De Leon'
        ];

        $middleNames = [
            'James', 'Michael', 'Robert', 'William', 'David',
            'Joseph', 'Thomas', 'Charles', 'Daniel', 'Matthew',
            'Rose', 'Marie', 'Ann', 'Grace', 'Faith',
            'Hope', 'Joy', 'Peace', 'Love', 'Charity',
            null, null, null, null, null // Some without middle names
        ];

        $positions = [
            'Senior Administrative Officer',
            'Chief Administrative Officer',
            'Supervising Administrative Officer',
            'Administrative Officer V',
            'Administrative Officer IV',
            'Administrative Officer III',
            'Administrative Officer II',
            'Administrative Officer I',
            'Personnel Officer',
            'Human Resource Management Officer',
            'Accounting Officer',
            'Budget Officer',
            'Planning Officer',
            'Monitoring and Evaluation Officer',
            'Information Technology Officer',
            'Technical Staff',
            'Field Staff',
            'Clerk',
            'Driver',
            'Security Guard'
        ];

        $departments = [
            'Department of Agriculture',
            'Planning and Monitoring Division',
            'Human Resource Management',
            'Accounting and Finance',
            'General Services',
            'ICT Unit',
            'Regional Operations',
            'Central Office',
            'Field Operations',
            'Administrative Services'
        ];

        // Create 65 personnel with long names
        for ($i = 1; $i <= 65; $i++) {
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];
            $middleName = $middleNames[array_rand($middleNames)];
            
            // Ensure some have very long names
            if ($i <= 10) {
                // First 10 have extra long names
                $firstName = 'Christopher' . ($i % 3 === 0 ? ' Alexander' : '');
                $lastName = 'Montgomery' . ($i % 2 === 0 ? '-Fitzgerald' : '');
                if ($i % 2 === 0) {
                    $middleName = 'Theodore Maximilian';
                }
            }
            
            Personnel::create([
                'username'   => 'user' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'password'   => Hash::make('password123'),
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'middle_name' => $middleName,
                'position'   => $positions[array_rand($positions)],
                'department' => $departments[array_rand($departments)],
                'is_active'  => $i % 5 !== 0, // every 5th user inactive
            ]);
        }
    }
}


