<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeriesInfo extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_episode_page_url',
        'series_page_url',
        'title',
        'title_url',
        'cover_image_url',
        'story',
        'categories',
        'actors',
    ];

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'actors' => 'array',
        ];
    }
}
