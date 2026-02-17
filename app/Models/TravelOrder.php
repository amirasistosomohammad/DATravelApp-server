<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TravelOrder extends Model
{
    use HasFactory;

    protected $table = 'travel_orders';

    protected $fillable = [
        'personnel_id',
        'travel_purpose',
        'destination',
        'official_station',
        'start_date',
        'end_date',
        'objectives',
        'per_diems_expenses',
        'per_diems_note',
        'assistant_or_laborers_allowed',
        'appropriation',
        'remarks',
        'status',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'per_diems_expenses' => 'decimal:2',
            'submitted_at' => 'datetime',
        ];
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TravelOrderAttachment::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(TravelOrderApproval::class)->orderBy('step_order');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** Get the first approval step that is still pending (for the current director). */
    public function getCurrentPendingApproval(): ?TravelOrderApproval
    {
        return $this->approvals()->where('status', 'pending')->orderBy('step_order')->first();
    }
}
