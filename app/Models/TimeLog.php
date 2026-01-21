<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'personnel_id',
        'director_id',
        'log_date',
        'time_in',
        'time_out',
        'remarks',
    ];

    protected $casts = [
        'log_date' => 'date',
        'time_in' => 'datetime:H:i:s',
        'time_out' => 'datetime:H:i:s',
    ];

    public function personnel()
    {
        return $this->belongsTo(Personnel::class);
    }

    public function director()
    {
        return $this->belongsTo(Director::class);
    }
}

