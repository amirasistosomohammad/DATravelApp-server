<?php

namespace Database\Seeders;

use App\Models\Director;
use App\Models\Personnel;
use App\Models\TravelOrder;
use App\Models\TravelOrderApproval;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TravelOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Creates comprehensive travel order data for presentation:
     * - Draft orders: 5 (not yet submitted)
     * - Pending orders: 8 (submitted, awaiting director action)
     * - Recommended orders: 6 (step 1 recommended, step 2 pending)
     * - Approved orders: 12 (fully approved by both directors)
     * - Rejected orders: 4 (rejected at various stages)
     * - Single-step approved: 2 (only one director, direct approval)
     * 
     * Total: 37 travel orders with proper approval chains
     * 
     * Uses active personnel from PersonnelSeeder and active directors from DirectorSeeder.
     * Includes all required fields: official_station, per_diems_note, assistant_or_laborers_allowed, etc.
     */
    public function run(): void
    {
        // Clear existing data if in local environment
        if (app()->environment('local')) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::table('travel_order_approvals')->truncate();
            DB::table('travel_order_attachments')->truncate();
            DB::table('travel_orders')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $personnel = Personnel::where('is_active', true)->get();
        $directors = Director::where('is_active', true)->orderBy('id')->get();

        if ($personnel->isEmpty() || $directors->count() < 2) {
            $this->command->warn('Not enough active personnel or directors. Skipping travel order seeding.');
            return;
        }

        // Use first two active directors as recommender and approver
        $recommender = $directors[0];
        $approver = $directors[1];

        $today = Carbon::today();

        $purposes = [
            'Regional planning and coordination meeting',
            'Technical assistance and monitoring visit',
            'Stakeholder consultation and validation workshop',
            'Training on new system rollout',
            'Field validation and data collection',
            'Partnership and MOA signing',
            'Budget hearing and presentation',
            'Agricultural extension services',
            'Farmers\' training and capacity building',
            'Crop monitoring and assessment',
            'Livestock inspection and evaluation',
            'Agricultural research and development',
            'Market analysis and survey',
            'Disaster response and recovery',
            'Policy implementation review',
        ];

        $destinations = [
            'Cagayan Valley (Region II)',
            'Central Luzon (Region III)',
            'CALABARZON (Region IV-A)',
            'Bicol Region (Region V)',
            'Western Visayas (Region VI)',
            'Central Visayas (Region VII)',
            'Eastern Visayas (Region VIII)',
            'Zamboanga Peninsula (Region IX)',
            'Northern Mindanao (Region X)',
            'Davao Region (Region XI)',
            'SOCCSKSARGEN (Region XII)',
            'Caraga (Region XIII)',
            'NCR - Central Office',
            'Cordillera Administrative Region (CAR)',
            'Bangsamoro Autonomous Region (BARMM)',
        ];

        $officialStations = [
            'Regional Office - Region III',
            'Central Office - NCR',
            'Provincial Office - Pampanga',
            'Regional Office - Region IV-A',
            'Field Office - Nueva Ecija',
            'Regional Office - Region V',
            'Provincial Office - Albay',
            'Central Office - Quezon City',
        ];

        $objectives = [
            'Attend meetings and conduct field validation.',
            'Coordinate with regional office and conduct monitoring.',
            'Attend training and validation workshop.',
            'Field visit and data gathering.',
            'Review implementation of agricultural programs.',
            'Conduct assessment and evaluation activities.',
            'Participate in planning and coordination sessions.',
            'Provide technical assistance to local farmers.',
            'Monitor project implementation and progress.',
            'Facilitate stakeholder consultations.',
        ];

        $perDiemsNotes = [
            'Per diem rate: PHP 500/day',
            'Per diem rate: PHP 600/day',
            'Per diem rate: PHP 700/day',
            'Per diem rate: PHP 800/day',
            'Per diem rate: PHP 1,000/day',
            null,
        ];

        $assistants = [
            '1 driver',
            '2 field staff',
            '1 administrative assistant',
            '1 technical staff',
            '2 drivers',
            null,
        ];

        $appropriations = [
            'GAA 2025',
            'GAA 2024',
            'Special Allotment Release Order (SARO)',
            'Continuing Appropriation',
            'Trust Fund',
        ];

        $remarks = [
            'Urgent travel required for immediate action.',
            'Travel approved subject to availability of funds.',
            'Travel necessary for program implementation.',
            null,
            null,
            null,
        ];

        $index = 0;

        // ============================================
        // DRAFT TRAVEL ORDERS (5 orders)
        // ============================================
        for ($i = 0; $i < 5; $i++) {
            $person = $personnel[$index % $personnel->count()];
            $start = $today->copy()->addDays(7 + $i * 3);
            $end = $start->copy()->addDays(rand(1, 3));

            TravelOrder::create([
                'personnel_id' => $person->id,
                'travel_purpose' => $purposes[$i % count($purposes)],
                'destination' => $destinations[$i % count($destinations)],
                'official_station' => $officialStations[$i % count($officialStations)],
                'start_date' => $start,
                'end_date' => $end,
                'objectives' => $objectives[$i % count($objectives)],
                'per_diems_expenses' => rand(3000, 8000),
                'per_diems_note' => $perDiemsNotes[$i % count($perDiemsNotes)],
                'assistant_or_laborers_allowed' => $assistants[$i % count($assistants)],
                'appropriation' => $appropriations[$i % count($appropriations)],
                'remarks' => $remarks[$i % count($remarks)],
                'status' => 'draft',
                'submitted_at' => null,
            ]);
            $index++;
        }

        // ============================================
        // PENDING TRAVEL ORDERS (8 orders)
        // ============================================
        for ($i = 0; $i < 8; $i++) {
            $person = $personnel[$index % $personnel->count()];
            $start = $today->copy()->addDays(14 + $i * 2);
            $end = $start->copy()->addDays(rand(1, 3));
            $submittedAt = $today->copy()->subDays(rand(1, 5));

            $order = TravelOrder::create([
                'personnel_id' => $person->id,
                'travel_purpose' => $purposes[($i + 2) % count($purposes)],
                'destination' => $destinations[($i + 2) % count($destinations)],
                'official_station' => $officialStations[($i + 1) % count($officialStations)],
                'start_date' => $start,
                'end_date' => $end,
                'objectives' => $objectives[($i + 1) % count($objectives)],
                'per_diems_expenses' => rand(4000, 9000),
                'per_diems_note' => $perDiemsNotes[($i + 1) % count($perDiemsNotes)],
                'assistant_or_laborers_allowed' => $assistants[($i + 1) % count($assistants)],
                'appropriation' => $appropriations[($i + 1) % count($appropriations)],
                'remarks' => $remarks[($i + 1) % count($remarks)],
                'status' => 'pending',
                'submitted_at' => $submittedAt,
            ]);

            // Create approval chain
            TravelOrderApproval::create([
                'travel_order_id' => $order->id,
                'director_id' => $recommender->id,
                'step_order' => 1,
                'status' => 'pending',
                'remarks' => null,
                'acted_at' => null,
            ]);

            TravelOrderApproval::create([
                'travel_order_id' => $order->id,
                'director_id' => $approver->id,
                'step_order' => 2,
                'status' => 'pending',
                'remarks' => null,
                'acted_at' => null,
            ]);

            $index++;
        }

        // ============================================
        // RECOMMENDED TRAVEL ORDERS (6 orders)
        // Recommending director recommended, but approving director hasn't acted yet
        // ============================================
        for ($i = 0; $i < 6; $i++) {
            $person = $personnel[$index % $personnel->count()];
            $start = $today->copy()->addDays(10 + $i * 2);
            $end = $start->copy()->addDays(rand(1, 3));
            $submittedAt = $today->copy()->subDays(rand(3, 7));
            $recommendedAt = $today->copy()->subDays(rand(1, 3));

            $order = TravelOrder::create([
                'personnel_id' => $person->id,
                'travel_purpose' => $purposes[($i + 5) % count($purposes)],
                'destination' => $destinations[($i + 5) % count($destinations)],
                'official_station' => $officialStations[($i + 2) % count($officialStations)],
                'start_date' => $start,
                'end_date' => $end,
                'objectives' => $objectives[($i + 2) % count($objectives)],
                'per_diems_expenses' => rand(4500, 8500),
                'per_diems_note' => $perDiemsNotes[($i + 2) % count($perDiemsNotes)],
                'assistant_or_laborers_allowed' => $assistants[($i + 2) % count($assistants)],
                'appropriation' => $appropriations[($i + 2) % count($appropriations)],
                'remarks' => $remarks[($i + 2) % count($remarks)],
                'status' => 'pending', // Still pending because step 2 hasn't acted
                'submitted_at' => $submittedAt,
            ]);

            TravelOrderApproval::create([
                'travel_order_id' => $order->id,
                'director_id' => $recommender->id,
                'step_order' => 1,
                'status' => 'recommended',
                'remarks' => 'Recommended for approval. Travel is necessary for program implementation.',
                'acted_at' => $recommendedAt,
            ]);

            TravelOrderApproval::create([
                'travel_order_id' => $order->id,
                'director_id' => $approver->id,
                'step_order' => 2,
                'status' => 'pending',
                'remarks' => null,
                'acted_at' => null,
            ]);

            $index++;
        }

        // ============================================
        // APPROVED TRAVEL ORDERS (12 orders)
        // Both steps completed - fully approved
        // ============================================
        for ($i = 0; $i < 12; $i++) {
            $person = $personnel[$index % $personnel->count()];
            $start = $today->copy()->subDays(rand(5, 30));
            $end = $start->copy()->addDays(rand(1, 4));
            $submittedAt = $today->copy()->subDays(rand(10, 35));
            $recommendedAt = $submittedAt->copy()->addDays(rand(1, 3));
            $approvedAt = $recommendedAt->copy()->addDays(rand(1, 2));

            $order = TravelOrder::create([
                'personnel_id' => $person->id,
                'travel_purpose' => $purposes[($i + 3) % count($purposes)],
                'destination' => $destinations[($i + 3) % count($destinations)],
                'official_station' => $officialStations[($i + 3) % count($officialStations)],
                'start_date' => $start,
                'end_date' => $end,
                'objectives' => $objectives[($i + 3) % count($objectives)],
                'per_diems_expenses' => rand(5000, 10000),
                'per_diems_note' => $perDiemsNotes[($i + 3) % count($perDiemsNotes)],
                'assistant_or_laborers_allowed' => $assistants[($i + 3) % count($assistants)],
                'appropriation' => $appropriations[($i + 3) % count($appropriations)],
                'remarks' => $remarks[($i + 3) % count($remarks)],
                'status' => 'approved',
                'submitted_at' => $submittedAt,
            ]);

            TravelOrderApproval::create([
                'travel_order_id' => $order->id,
                'director_id' => $recommender->id,
                'step_order' => 1,
                'status' => 'recommended',
                'remarks' => 'Recommended for approval. All requirements are in order.',
                'acted_at' => $recommendedAt,
            ]);

            TravelOrderApproval::create([
                'travel_order_id' => $order->id,
                'director_id' => $approver->id,
                'step_order' => 2,
                'status' => 'approved',
                'remarks' => 'Approved. Travel order is in order and within budget.',
                'acted_at' => $approvedAt,
            ]);

            $index++;
        }

        // ============================================
        // REJECTED TRAVEL ORDERS (4 orders)
        // Some rejected at step 1, some at step 2
        // ============================================
        for ($i = 0; $i < 4; $i++) {
            $person = $personnel[$index % $personnel->count()];
            $start = $today->copy()->addDays(rand(5, 15));
            $end = $start->copy()->addDays(rand(1, 2));
            $submittedAt = $today->copy()->subDays(rand(2, 5));
            $actedAt = $submittedAt->copy()->addDays(rand(1, 2));

            $order = TravelOrder::create([
                'personnel_id' => $person->id,
                'travel_purpose' => $purposes[($i + 8) % count($purposes)],
                'destination' => $destinations[($i + 8) % count($destinations)],
                'official_station' => $officialStations[($i + 4) % count($officialStations)],
                'start_date' => $start,
                'end_date' => $end,
                'objectives' => $objectives[($i + 4) % count($objectives)],
                'per_diems_expenses' => rand(3000, 6000),
                'per_diems_note' => $perDiemsNotes[($i + 4) % count($perDiemsNotes)],
                'assistant_or_laborers_allowed' => $assistants[($i + 4) % count($assistants)],
                'appropriation' => $appropriations[($i + 4) % count($appropriations)],
                'remarks' => $remarks[($i + 4) % count($remarks)],
                'status' => 'rejected',
                'submitted_at' => $submittedAt,
            ]);

            if ($i < 2) {
                // Rejected at step 1 (recommending director)
                TravelOrderApproval::create([
                    'travel_order_id' => $order->id,
                    'director_id' => $recommender->id,
                    'step_order' => 1,
                    'status' => 'rejected',
                    'remarks' => 'Insufficient justification for travel at this time. Please provide more details.',
                    'acted_at' => $actedAt,
                ]);

                TravelOrderApproval::create([
                    'travel_order_id' => $order->id,
                    'director_id' => $approver->id,
                    'step_order' => 2,
                    'status' => 'pending',
                    'remarks' => null,
                    'acted_at' => null,
                ]);
            } else {
                // Rejected at step 2 (approving director)
                TravelOrderApproval::create([
                    'travel_order_id' => $order->id,
                    'director_id' => $recommender->id,
                    'step_order' => 1,
                    'status' => 'recommended',
                    'remarks' => 'Recommended for approval.',
                    'acted_at' => $actedAt,
                ]);

                TravelOrderApproval::create([
                    'travel_order_id' => $order->id,
                    'director_id' => $approver->id,
                    'step_order' => 2,
                    'status' => 'rejected',
                    'remarks' => 'Budget constraints; please reschedule for next quarter.',
                    'acted_at' => $actedAt->copy()->addHour(),
                ]);
            }

            $index++;
        }

        // ============================================
        // SINGLE-STEP APPROVAL (2 orders)
        // Only one director assigned (can approve directly)
        // ============================================
        for ($i = 0; $i < 2; $i++) {
            $person = $personnel[$index % $personnel->count()];
            $start = $today->copy()->subDays(rand(3, 10));
            $end = $start->copy()->addDays(rand(1, 2));
            $submittedAt = $today->copy()->subDays(rand(5, 12));
            $approvedAt = $submittedAt->copy()->addDays(rand(1, 2));

            $order = TravelOrder::create([
                'personnel_id' => $person->id,
                'travel_purpose' => $purposes[($i + 10) % count($purposes)],
                'destination' => $destinations[($i + 10) % count($destinations)],
                'official_station' => $officialStations[($i + 5) % count($officialStations)],
                'start_date' => $start,
                'end_date' => $end,
                'objectives' => $objectives[($i + 5) % count($objectives)],
                'per_diems_expenses' => rand(4000, 7000),
                'per_diems_note' => $perDiemsNotes[($i + 5) % count($perDiemsNotes)],
                'assistant_or_laborers_allowed' => $assistants[($i + 5) % count($assistants)],
                'appropriation' => $appropriations[($i + 5) % count($appropriations)],
                'remarks' => $remarks[($i + 5) % count($remarks)],
                'status' => 'approved',
                'submitted_at' => $submittedAt,
            ]);

            // Only one approval step (director can approve directly)
            TravelOrderApproval::create([
                'travel_order_id' => $order->id,
                'director_id' => $recommender->id,
                'step_order' => 1,
                'status' => 'approved',
                'remarks' => 'Approved. Single-step approval.',
                'acted_at' => $approvedAt,
            ]);

            $index++;
        }

        $this->command->info('Travel orders seeded successfully!');
        $this->command->info('Created:');
        $this->command->info('  - 5 Draft orders');
        $this->command->info('  - 8 Pending orders');
        $this->command->info('  - 6 Recommended orders (step 1 done, step 2 pending)');
        $this->command->info('  - 12 Approved orders');
        $this->command->info('  - 4 Rejected orders');
        $this->command->info('  - 2 Single-step approved orders');
        $this->command->info('Total: 37 travel orders');
    }
}
