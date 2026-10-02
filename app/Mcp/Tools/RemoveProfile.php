<?php

namespace App\Mcp\Tools;

use App\Models\Profile;
use App\Rules\XProfileHandle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Stops monitoring an X profile. Accepts a profile URL, an @handle, or a bare username. Its stored posts are kept.')]
class RemoveProfile extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'username' => ['required', 'string', new XProfileHandle],
        ], [
            'username.required' => 'Provide the username, @handle or profile URL to stop monitoring.',
        ]);

        $username = Profile::usernameFromInput($validated['username']);

        $profile = Profile::query()->where('username', $username)->first();

        if ($profile === null) {
            return Response::error("@{$username} is not being monitored.");
        }

        $profile->delete();

        return Response::text("@{$username} is no longer monitored.");
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'username' => $schema->string()
                ->description('Username, @handle or profile URL of the monitored profile.')
                ->required(),
        ];
    }
}
