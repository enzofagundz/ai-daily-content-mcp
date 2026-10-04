<?php

namespace App\Models;

use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $profile_id
 * @property string $external_id
 * @property string $url
 * @property string $text
 * @property string $author
 * @property Carbon $published_at
 * @property Carbon|null $presented_at
 * @property string $classification_status
 * @property bool|null $classification_relevant
 * @property float|null $classification_score
 * @property string|null $classification_category
 * @property float|null $classification_content_value_score
 * @property float|null $classification_adaptability_score
 * @property bool|null $classification_profile_fit
 * @property bool|null $classification_requires_missing_media
 * @property Carbon|null $classified_at
 * @property string|null $classification_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Profile $profile
 */
#[Fillable([
    'profile_id',
    'external_id',
    'url',
    'text',
    'author',
    'published_at',
    'classification_status',
    'classification_relevant',
    'classification_score',
    'classification_category',
    'classification_content_value_score',
    'classification_adaptability_score',
    'classification_profile_fit',
    'classification_requires_missing_media',
    'classified_at',
    'classification_error',
])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    public const CLASSIFICATION_PENDING = 'pending';

    public const CLASSIFICATION_CLASSIFIED = 'classified';

    public const CLASSIFICATION_FAILED = 'failed';

    /**
     * @return BelongsTo<Profile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Restrict to posts whose monitored profile still exists.
     *
     * Posts outlive a soft-deleted profile, so without this filter the
     * `profile` relation resolves to null and reading its username crashes.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeFromMonitoredProfile(Builder $query): void
    {
        $query->whereHas('profile');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'presented_at' => 'datetime',
            'classification_relevant' => 'boolean',
            'classification_score' => 'float',
            'classification_content_value_score' => 'float',
            'classification_adaptability_score' => 'float',
            'classification_profile_fit' => 'boolean',
            'classification_requires_missing_media' => 'boolean',
            'classified_at' => 'datetime',
        ];
    }
}
