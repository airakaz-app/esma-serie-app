<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoWatchHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'video_key',
        'video_url',
        'current_time',
        'duration',
        'completed',
        'last_watched_at',
    ];

    protected function casts(): array
    {
        return [
            'current_time' => 'integer',
            'duration' => 'integer',
            'completed' => 'boolean',
            'last_watched_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
