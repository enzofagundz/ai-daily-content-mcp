<?php

use App\Mcp\Servers\DailyContentServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('daily-content', DailyContentServer::class);
