<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelOrderEditor extends Model
{
    protected $table = 'travel_order_editors';

    protected $fillable = [
        'travel_order_id',
        'personnel_id',
        'invited_by',
    ];

    public function travelOrder(): BelongsTo
    {
        return $this->belongsTo(TravelOrder::class);
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'invited_by');
    }
}
