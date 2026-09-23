<?php

use App\Http\Middleware\AuthenticateOrgToken;
use App\Http\Middleware\McpRateLimit;
use App\Mcp\Servers\ArtfctServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', ArtfctServer::class)
    ->middleware([
        AuthenticateOrgToken::class,
        McpRateLimit::class,
    ]);
