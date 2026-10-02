<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddProfile;
use App\Mcp\Tools\FetchRecentPosts;
use App\Mcp\Tools\ListProfiles;
use App\Mcp\Tools\RemoveProfile;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('Daily Content')]
#[Version('0.1.0')]
#[Instructions('Monitors X profiles and fetches their recent posts, handing the agent only posts it has not seen yet.')]
class DailyContentServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        AddProfile::class,
        ListProfiles::class,
        RemoveProfile::class,
        FetchRecentPosts::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [];
}
