<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Series extends Model
{
    use HasFactory;

    // Status constants
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_DUPLICATE = 'duplicate';

    // Source constants
    public const SOURCE_ESHEAQ = 'esheaq';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'titre',
        'url',
        'image',
        'source',
        'status',
        'last_scraped_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_scraped_at' => 'datetime',
    ];

    /**
     * Scope: Filter active series
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope: Filter by source
     */
    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Scope: Filter recently scraped series
     */
    public function scopeRecentlyScraped($query, int $minutes = 1440)
    {
        return $query->where('last_scraped_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Scope: Order by most recently scraped
     */
    public function scopeNewestFirst($query)
    {
        return $query->orderBy('last_scraped_at', 'desc');
    }

    /**
     * Get all active series for display
     */
    public static function getActiveForDisplay()
    {
        return self::active()
            ->bySource(self::SOURCE_ESHEAQ)
            ->select(['id', 'titre', 'url', 'image'])
            ->orderBy('titre', 'asc')
            ->get();
    }
}
