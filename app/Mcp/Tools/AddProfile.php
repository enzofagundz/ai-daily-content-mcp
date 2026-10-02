<?php

namespace App\Mcp\Tools;

use App\Models\Profile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Adds an X profile to monitor. Accepts a profile URL (x.com or twitter.com), an @handle, or a bare username.')]
class AddProfile extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'url' => ['required', 'string'],
        ], [
            'url.required' => 'Provide the profile URL (https://x.com/theo), @handle or username to monitor.',
        ]);

        try {
            $username = Profile::usernameFromInput($validated['url']);
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        $profile = Profile::withTrashed()->firstOrNew(['username' => $username]);
        $profile->url = "https://x.com/{$username}";
        $profile->deleted_at = null;
        $profile->save();

        return Response::text("@{$username} is now monitored.");
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('Profile URL (https://x.com/theo), @handle or username.')
                ->required(),
        ];
    }
}
