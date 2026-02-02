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
        $personnel = Personnel::where('is_active', true)->limit(20)->get();
        $directors = Director::where('is_active', true)->get();
        if ($personnel->isEmpty() && $directors->isEmpty()) {
            return;
        }

        $today = Carbon::today();
        $timeInOptions = ['07:30', '07:45', '08:00', '08:05', '08:15', '08:30'];
        $timeOutOptions = ['16:30', '16:45', '17:00', '17:10', '17:15', '17:30'];

        // Seed last 14 days for personnel
        foreach ($personnel as $index => $person) {
            for ($daysAgo = 0; $daysAgo < 14; $daysAgo++) {
                $logDate = $today->copy()->subDays($daysAgo)->toDateString();
                $timeIn = $timeInOptions[$index % count($timeInOptions)];
                $isToday = ($daysAgo === 0);
                $timeOut = $isToday ? null : $timeOutOptions[$index % count($timeOutOptions)];
                TimeLog::updateOrCreate(
                    [
                        'personnel_id' => $person->id,
                        'log_date' => $logDate,
                        'time_in' => $timeIn,
                    ],
                    [
                        'director_id' => null,
                        'time_out' => $timeOut,
                        'remarks' => $daysAgo === 1 ? 'Field work' : null,
                    ]
                );
            }
        }

        // Seed last 14 days for directors
        foreach ($directors as $index => $director) {
            for ($daysAgo = 0; $daysAgo < 14; $daysAgo++) {
                $logDate = $today->copy()->subDays($daysAgo)->toDateString();
                $timeIn = $timeInOptions[($index + 1) % count($timeInOptions)];
                $isToday = ($daysAgo === 0);
                $timeOut = $isToday ? null : $timeOutOptions[($index + 1) % count($timeOutOptions)];
                TimeLog::updateOrCreate(
                    [
                        'director_id' => $director->id,
                        'log_date' => $logDate,
                        'time_in' => $timeIn,
                    ],
                    [
                        'personnel_id' => null,
                        'time_out' => $timeOut,
                        'remarks' => null,
                    ]
                );
            }
        }
    }
}

