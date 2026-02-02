<?php

namespace Database\Seeders;

use App\Models\Director;
use App\Models\Personnel;
use App\Models\TravelOrder;
use App\Models\TravelOrderApproval;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class TravelOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $personnel = Personnel::where('is_active', true)->limit(15)->get();
        $directors = Director::where('is_active', true)->orderBy('id')->limit(2)->get();
        if ($personnel->isEmpty() || $directors->count() < 2) {
            return;
        }

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
        ];
        $destinations = [
            'Cagayan Valley (Region II)',
            'Central Luzon (Region III)',
            'CALABARZON (Region IV-A)',
            'Bicol Region (Region V)',
            'Western Visayas (Region VI)',
            'Central Visayas (Region VII)',
            'Davao Region (Region XI)',
            'SOCCSKSARGEN (Region XII)',
            'NCR - Central Office',
        ];

        $index = 0;

        // 3 draft travel orders (no approvals, not submitted)
        for ($i = 0; $i < 3; $i++) {
            $person = $personnel[$index % $personnel->count()];
            $start = $today->copy()->addDays(7 + $i * 3);
            $end = $start->copy()->addDays(2);
            TravelOrder::create([
                'personnel_id' => $person->id,
                'travel_purpose' => $purposes[$i % count($purposes)],
                'destination' => $destinations[$i % count($destinations)],
                'start_date' => $start,
                'end_date' => $end,
                'objectives' => "Attend meetings and conduct field validation.",
                'per_diems_expenses' => 5000 + ($i * 500),
                'appropriation' => 'GAA 2025',
                'remarks' => null,
                'status' => 'draft',
                'submitted_at' => null,
            ]);
            $index++;
        }

        // 2 pending travel orders (submitted, approvals pending)
        for ($i = 0; $i < 2; $i++) {
            $person = $personnel[$index % $personnel->count()];
            $start = $today->copy()->addDays(14 + $i * 2);
            $end = $start->copy()->addDays(1);
            $order = TravelOrder::create([
                'personnel_id' => $person->id,
                'travel_purpose' => $purposes[($i + 2) % count($purposes)],
                'destination' => $destinations[($i + 2) % count($destinations)],
                'start_date' => $start,
                'end_date' => $end,
                'objectives' => "Coordinate with regional office and conduct monitoring.",
                'per_diems_expenses' => 4500,
                'appropriation' => 'GAA 2025',
                'remarks' => null,
                'status' => 'pending',
                'submitted_at' => $today->copy()->subDays(1),
            ]);
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

        // 4 approved travel orders (recommend + approve)
        for ($i = 0; $i < 4; $i++) {
            $person = $personnel[$index % $personnel->count()];
            $start = $today->copy()->subDays(10 - $i * 2);
            $end = $start->copy()->addDays(2);
            $order = TravelOrder::create([
                'personnel_id' => $person->id,
                'travel_purpose' => $purposes[($i + 1) % count($purposes)],
                'destination' => $destinations[($i + 1) % count($destinations)],
                'start_date' => $start,
                'end_date' => $end,
                'objectives' => "Attend training and validation workshop.",
                'per_diems_expenses' => 6000,
                'appropriation' => 'GAA 2025',
                'remarks' => null,
                'status' => 'approved',
                'submitted_at' => $today->copy()->subDays(15 - $i),
            ]);
            $actedAt = $today->copy()->subDays(14 - $i);
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
                'status' => 'approved',
                'remarks' => 'Approved.',
                'acted_at' => $actedAt->copy()->addHour(),
            ]);
            $index++;
        }

        // 2 rejected travel orders (one step rejected)
        for ($i = 0; $i < 2; $i++) {
            $person = $personnel[$index % $personnel->count()];
            $start = $today->copy()->addDays(5 + $i);
            $end = $start->copy()->addDays(1);
            $order = TravelOrder::create([
                'personnel_id' => $person->id,
                'travel_purpose' => $purposes[($i + 5) % count($purposes)],
                'destination' => $destinations[($i + 5) % count($destinations)],
                'start_date' => $start,
                'end_date' => $end,
                'objectives' => "Field visit and data gathering.",
                'per_diems_expenses' => 3500,
                'appropriation' => 'GAA 2025',
                'remarks' => null,
                'status' => 'rejected',
                'submitted_at' => $today->copy()->subDays(2),
            ]);
            $actedAt = $today->copy()->subDays(1);
            if ($i === 0) {
                TravelOrderApproval::create([
                    'travel_order_id' => $order->id,
                    'director_id' => $recommender->id,
                    'step_order' => 1,
                    'status' => 'rejected',
                    'remarks' => 'Insufficient justification for travel at this time.',
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
                TravelOrderApproval::create([
                    'travel_order_id' => $order->id,
                    'director_id' => $recommender->id,
                    'step_order' => 1,
                    'status' => 'recommended',
                    'remarks' => 'Recommended.',
                    'acted_at' => $actedAt,
                ]);
                TravelOrderApproval::create([
                    'travel_order_id' => $order->id,
                    'director_id' => $approver->id,
                    'step_order' => 2,
                    'status' => 'rejected',
                    'remarks' => 'Budget constraints; please reschedule.',
                    'acted_at' => $actedAt->copy()->addHour(),
                ]);
            }
            $index++;
        }
    }
}
