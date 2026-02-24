<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\TravelOrderEditor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TravelOrder extends Model
{
    use HasFactory;

    protected $table = 'travel_orders';

    protected $fillable = [
        'personnel_id',
        'to_personnel_id',
        'to_name',
        'to_position',
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
        'cancellation_remarks',
        'status',
        'submitted_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'per_diems_expenses' => 'decimal:2',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    /** Editors invited by the creator to edit this travel order (e.g. when created for someone else). */
    public function editors(): HasMany
    {
        return $this->hasMany(TravelOrderEditor::class);
    }

    /** Personnel who can edit this TO (creator is not in this list; check personnel_id for owner). */
    public function editorPersonnel(): BelongsToMany
    {
        return $this->belongsToMany(Personnel::class, 'travel_order_editors', 'travel_order_id', 'personnel_id')
            ->withPivot('invited_by')
            ->withTimestamps();
    }

    /** Whether the given personnel can view and (when draft) edit this travel order. */
    public function canBeEditedBy(Personnel $personnel): bool
    {
        if ((int) $this->personnel_id === (int) $personnel->id) {
            return true;
        }
        return $this->editors()->where('personnel_id', $personnel->id)->exists();
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
