<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelOrderApproval extends Model
{
    use HasFactory;

    protected $table = 'travel_order_approvals';

    protected $fillable = [
        'travel_order_id',
        'director_id',
        'step_order',
        'status',
        'remarks',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
        ];
    }

    public function travelOrder(): BelongsTo
    {
        return $this->belongsTo(TravelOrder::class);
    }

    public function director(): BelongsTo
    {
        return $this->belongsTo(Director::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isRecommendStep(): bool
    {
        return $this->step_order === 1;
    }

    public function isApproveStep(): bool
    {
        return $this->step_order === 2;
    }
}
