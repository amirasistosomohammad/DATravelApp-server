<?php

namespace Database\Seeders;

use App\Models\Director;
use App\Models\Personnel;
use App\Models\TimeLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class TimeLogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $personnel = Personnel::limit(3)->get();
        $directors = Director::limit(2)->get();
        if ($personnel->isEmpty() && $directors->isEmpty()) {
            return;
        }

        $today = Carbon::today();

        $entries = [
            [
                'log_date' => $today->copy()->subDays(2)->toDateString(),
                'time_in' => '08:05',
                'time_out' => '17:10',
                'remarks' => null,
            ],
            [
                'log_date' => $today->copy()->subDays(1)->toDateString(),
                'time_in' => '08:12',
                'time_out' => '16:45',
                'remarks' => null,
            ],
            [
                'log_date' => $today->toDateString(),
                'time_in' => '08:00',
                'time_out' => null,
                'remarks' => null,
            ],
        ];

        foreach ($personnel as $index => $person) {
            $entry = $entries[$index % count($entries)];
            TimeLog::updateOrCreate(
                [
                    'personnel_id' => $person->id,
                    'log_date' => $entry['log_date'],
                    'time_in' => $entry['time_in'],
                ],
                [
                    'director_id' => null,
                    'time_out' => $entry['time_out'],
                    'remarks' => $entry['remarks'],
                ]
            );
        }

        foreach ($directors as $index => $director) {
            $entry = $entries[($index + 1) % count($entries)];
            TimeLog::updateOrCreate(
                [
                    'director_id' => $director->id,
                    'log_date' => $entry['log_date'],
                    'time_in' => $entry['time_in'],
                ],
                [
                    'personnel_id' => null,
                    'time_out' => $entry['time_out'],
                    'remarks' => $entry['remarks'],
                ]
            );
        }
    }
}

