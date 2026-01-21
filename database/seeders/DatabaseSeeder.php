<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed ICT Admin and sample Personnel accounts
        $this->call([
            IctAdminSeeder::class,
            PersonnelSeeder::class,
            DirectorSeeder::class,
            TimeLogSeeder::class,
        ]);
    }
}
