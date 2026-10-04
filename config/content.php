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

];
