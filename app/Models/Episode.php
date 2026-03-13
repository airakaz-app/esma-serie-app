<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Episode extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'series_info_id',
        'title',
        'page_url',
        'episode_number',
        'image_url',
        'status',
        'error_message',
        'last_scraped_at',
    ];

    protected function casts(): array
    {
        return [
            'episode_number' => 'integer',
            'last_scraped_at' => 'datetime',
        ];
    }


    public function seriesInfo(): BelongsTo
    {
        return $this->belongsTo(SeriesInfo::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(EpisodeServer::class);
    }
}
