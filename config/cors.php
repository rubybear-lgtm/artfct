<?php

/*
| Cross-origin access for browser-based MCP clients (MCP Inspector, web
| connectors). Only the machine endpoints are exposed: discovery documents,
| client registration, the token endpoints, and /mcp itself. All of them are
| authenticated by bearer tokens or PKCE, never cookies, so any origin is
| allowed and credentials stay off. Browser routes, including the consent
| screen, are deliberately not listed.
*/

return [

    'paths' => [
        'mcp',
        '.well-known/*',
        'oauth/register',
        'oauth/token',
        'oauth/revoke',
        'oauth/organizations',
    ],

    'allowed_methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['MCP-Session-Id', 'WWW-Authenticate', 'Retry-After'],

    'max_age' => 600,

    'supports_credentials' => false,

];
