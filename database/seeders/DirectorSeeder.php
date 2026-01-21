<?php

namespace Database\Seeders;

use App\Models\Director;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DirectorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $records = [
            [
                'username' => 'director1',
                'email' => 'director1@datravel.test',
                'first_name' => 'Maria',
                'middle_name' => 'L.',
                'last_name' => 'Santos',
                'phone' => '0912-345-6789',
                'contact_information' => 'maria.santos@datravel.test',
                'position' => 'Director III',
                'department' => 'Department of Agriculture',
                'director_level' => 'Regional Director',
                'is_active' => true,
            ],
            [
                'username' => 'director2',
                'email' => 'director2@datravel.test',
                'first_name' => 'Jose',
                'middle_name' => 'R.',
                'last_name' => 'Reyes',
                'phone' => '0922-555-1234',
                'contact_information' => 'jose.reyes@datravel.test',
                'position' => 'OIC – Regional Executive Director',
                'department' => null,
                'director_level' => 'Assistant Director',
                'is_active' => true,
            ],
            [
                'username' => 'director_inactive',
                'email' => 'director_inactive@datravel.test',
                'first_name' => 'Ana',
                'middle_name' => '',
                'last_name' => 'Dela Cruz',
                'phone' => '0933-777-8888',
                'contact_information' => 'ana.delacruz@datravel.test',
                'position' => 'Director IV',
                'department' => 'Department of Agriculture',
                'director_level' => 'Head Director',
                'is_active' => false,
                'reason_for_deactivation' => 'Account deactivated for testing.',
            ],
        ];

        foreach ($records as $data) {
            $nameParts = array_filter([
                $data['first_name'] ?? null,
                $data['middle_name'] ?? null,
                $data['last_name'] ?? null,
            ], function ($value) {
                return $value !== null && trim((string) $value) !== '';
            });

            $payload = [
                'username' => $data['username'],
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?: null,
                'last_name' => $data['last_name'],
                'name' => trim(implode(' ', $nameParts)),
                'phone' => $data['phone'] ?: null,
                'contact_information' => $data['contact_information'] ?: null,
                'position' => $data['position'] ?? null,
                'department' => $data['department'] ?? null,
                'director_level' => $data['director_level'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'reason_for_deactivation' => $data['reason_for_deactivation'] ?? null,
                'password' => Hash::make('Password123'),
                'remember_token' => Str::random(10),
            ];

            Director::updateOrCreate(
                ['username' => $payload['username']],
                $payload
            );
        }
    }
}

