<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpisodeServer extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'episode_id',
        'server_name',
        'host',
        'server_page_url',
        'iframe_url',
        'click_success',
        'final_url',
        'result_title',
        'result_h1',
        'result_preview',
        'status',
        'retry_count',
        'error_message',
        'last_scraped_at',
    ];

    protected function casts(): array
    {
        return [
            'click_success' => 'boolean',
            'last_scraped_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }
}
