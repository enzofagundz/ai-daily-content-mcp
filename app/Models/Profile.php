<?php

namespace App\Models;

use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $username
 * @property string $url
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['username', 'url'])]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Extract the username from a profile URL, "@handle" or bare username.
     *
     * @throws InvalidArgumentException when the input is not a valid X profile.
     */
    public static function usernameFromInput(string $input): string
    {
        $input = trim($input);

        if (preg_match('~^(?:https?://)?(?:www\.)?(?:x|twitter)\.com/([^/?#]+)~i', $input, $matches) === 1) {
            $username = $matches[1];
        } else {
            $username = ltrim($input, '@');
        }

        $username = strtolower($username);

        if (preg_match('~^[a-z0-9_]{1,15}$~', $username) !== 1) {
            throw new InvalidArgumentException(
                "Invalid X profile \"{$input}\". Provide an x.com/twitter.com URL, @handle or username.",
            );
        }

        return $username;
    }
}
