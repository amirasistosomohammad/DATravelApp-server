<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelOrderAttachment extends Model
{
    use HasFactory;

    protected $table = 'travel_order_attachments';

    protected $fillable = [
        'travel_order_id',
        'file_path',
        'file_name',
        'type',
    ];

    public function travelOrder(): BelongsTo
    {
        return $this->belongsTo(TravelOrder::class);
    }
}
