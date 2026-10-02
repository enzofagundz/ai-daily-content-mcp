<?php

namespace App\Mcp\Tools;

use App\Models\Profile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lists all X profiles currently monitored.')]
class ListProfiles extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $lines = Profile::query()
            ->orderBy('username')
            ->get()
            ->map(fn (Profile $profile): string => "@{$profile->username} — {$profile->url}")
            ->all();

        if ($lines === []) {
            return Response::text('No profiles are being monitored.');
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
