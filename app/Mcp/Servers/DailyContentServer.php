<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddProfile;
use App\Mcp\Tools\ClassifyPendingPosts;
use App\Mcp\Tools\ClassifyPost;
use App\Mcp\Tools\FetchRecentPosts;
use App\Mcp\Tools\GetNewPosts;
use App\Mcp\Tools\GetRelevantPosts;
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
#[Instructions('Monitors X profiles and classifies their posts with Cloudflare Clef for LinkedIn content triage. Build the daily digest from a single get_new_posts call: it collects, classifies and returns recent posts with their classification, and candidates are the returned posts with classification.relevant true. Posts published before the recency window are never presented as new. Use get_relevant_posts only as a manual lookup over the standing list of relevant posts, and classify_pending_posts to catch up on posts whose classification is still pending. The MCP only collects, classifies and serves posts; it never writes or publishes LinkedIn content.')]
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
        GetNewPosts::class,
        ClassifyPost::class,
        ClassifyPendingPosts::class,
        GetRelevantPosts::class,
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
