<?php

namespace Database\Seeders;

use App\Models\IctAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class IctAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default ICT Admin account
        IctAdmin::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'username' => 'admin@admin.com',
                'email' => 'admin@admin.com',
                'password' => Hash::make('123456'),
                'name' => 'ICT Administrator',
                'position' => 'ICT Administrator',
                'department' => 'Department of Agriculture',
                'is_active' => true,
            ]
        );

        $this->command->info('Default ICT Admin account created successfully!');
        $this->command->info('Email: admin@admin.com');
        $this->command->info('Password: 123456');
    }
}

