<?php

namespace Database\Seeders;

use App\Models\Director;
use App\Models\Personnel;
use App\Models\TimeLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TimeLogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Optimized to use bulk inserts instead of individual updateOrCreate calls.
     */
    public function run(): void
    {
        // Clear existing time logs if in local environment
        if (app()->environment('local')) {
            DB::table('time_logs')->truncate();
        }

        $personnel = Personnel::where('is_active', true)->limit(20)->get();
        $directors = Director::where('is_active', true)->get();
        if ($personnel->isEmpty() && $directors->isEmpty()) {
            return;
        }

        $today = Carbon::today();
        $timeInOptions = ['07:30', '07:45', '08:00', '08:05', '08:15', '08:30'];
        $timeOutOptions = ['16:30', '16:45', '17:00', '17:10', '17:15', '17:30'];

        $timeLogs = [];

        // Prepare time logs for personnel (last 14 days)
        foreach ($personnel as $index => $person) {
            for ($daysAgo = 0; $daysAgo < 14; $daysAgo++) {
                $logDate = $today->copy()->subDays($daysAgo)->toDateString();
                $timeIn = $timeInOptions[$index % count($timeInOptions)];
                $isToday = ($daysAgo === 0);
                $timeOut = $isToday ? null : $timeOutOptions[$index % count($timeOutOptions)];

                $timeLogs[] = [
                    'personnel_id' => $person->id,
                    'director_id' => null,
                    'log_date' => $logDate,
                    'time_in' => $timeIn,
                    'time_out' => $timeOut,
                    'remarks' => $daysAgo === 1 ? 'Field work' : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Prepare time logs for directors (last 14 days)
        foreach ($directors as $index => $director) {
            for ($daysAgo = 0; $daysAgo < 14; $daysAgo++) {
                $logDate = $today->copy()->subDays($daysAgo)->toDateString();
                $timeIn = $timeInOptions[($index + 1) % count($timeInOptions)];
                $isToday = ($daysAgo === 0);
                $timeOut = $isToday ? null : $timeOutOptions[($index + 1) % count($timeOutOptions)];

                $timeLogs[] = [
                    'personnel_id' => null,
                    'director_id' => $director->id,
                    'log_date' => $logDate,
                    'time_in' => $timeIn,
                    'time_out' => $timeOut,
                    'remarks' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Bulk insert all time logs in chunks for better performance
        if (!empty($timeLogs)) {
            $chunks = array_chunk($timeLogs, 100);
            foreach ($chunks as $chunk) {
                DB::table('time_logs')->insert($chunk);
            }
        }

        $this->command->info('Time logs seeded successfully!');
        $this->command->info('Created ' . count($timeLogs) . ' time log records.');
    }
}

