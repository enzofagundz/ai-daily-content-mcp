<?php

namespace App\Models;

use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Profile $profile
 */
#[Fillable(['profile_id', 'external_id', 'url', 'text', 'author', 'published_at'])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Profile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
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
        ];
    }
}
