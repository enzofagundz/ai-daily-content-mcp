<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content collection
    |--------------------------------------------------------------------------
    |
    | Recency window, in hours, used when presenting collected posts. Posts
    | published before this window stay in the database but are never
    | presented as new.
    |
    */

    'window_hours' => (int) env('CONTENT_WINDOW_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Catch-up cap
    |--------------------------------------------------------------------------
    |
    | When the last successful presentation is older than the recency window,
    | the window is extended back to it so a missed run does not drop posts.
    | This caps how far back that catch-up can reach.
    |
    */

    'max_lookback_hours' => (int) env('CONTENT_MAX_LOOKBACK_HOURS', 168),

];
