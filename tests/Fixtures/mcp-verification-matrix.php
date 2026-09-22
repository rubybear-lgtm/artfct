<?php

/**
 * The MCP verification matrix, as data rather than prose.
 *
 * RUB-363 asked for a verification gate covering transports, protocol
 * operations, OAuth, tenancy, clients, robustness and ops. This file is that
 * gate's source of truth: every capability the issue names appears exactly
 * once, pointing either at the test that covers it or at the issue filed for
 * the gap. McpVerificationMatrixTest asserts the pointers resolve, so renaming
 * or deleting a mapped test fails the suite instead of silently leaving a
 * capability uncovered.
 *
 * Views:
 *
 * @see tests/Feature/McpVerificationMatrixTest.php
 */

return [
    // ── Transports ────────────────────────────────────────────────────────
    'transport.stdio' => [
        'surface' => 'transport',
        'gap' => [
            'issue' => 'RUB-384',
            'reason' => 'Protocol logic is tested in process and argument parsing is covered, but nothing drives the stdio loop itself; a stray stdout write would corrupt every host client session and no test would notice.',
        ],
    ],
    'transport.streamable_http' => [
        'surface' => 'transport',
        'evidence' => [
            'kind' => 'php',
            'file' => 'tests/Feature/McpRemoteTransportTest.php',
            'name' => 'serves the native Streamable HTTP MCP transport with bearer authentication',
        ],
    ],

    // ── Protocol operations ───────────────────────────────────────────────
    'protocol.initialize' => [
        'surface' => 'protocol',
        'evidence' => ['kind' => 'rust', 'file' => 'mcp-server/src/mcp.rs', 'name' => 'initialize_captures_client_info'],
    ],
    'protocol.initialize_tolerates_empty_params' => [
        'surface' => 'protocol',
        'evidence' => ['kind' => 'rust', 'file' => 'mcp-server/src/mcp.rs', 'name' => 'initialize_with_empty_params_does_not_error'],
    ],
    'protocol.version_negotiation' => [
        'surface' => 'protocol',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpRemoteTransportTest.php', 'name' => 'remote MCP negotiates supported protocol versions and rejects unsupported ones'],
    ],
    'protocol.tool_discovery' => [
        'surface' => 'protocol',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpContractTest.php', 'name' => 'the hosted MCP catalog exposes the stable cross-transport contract'],
    ],
    'protocol.tool_call' => [
        'surface' => 'protocol',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpRemoteTransportTest.php', 'name' => 'deploy_artifact publishes a permanent org artifact in two steps'],
    ],
    'protocol.errors' => [
        'surface' => 'protocol',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpRemoteTransportTest.php', 'name' => 'remote tool failures expose stable safe error metadata'],
    ],
    'protocol.cancellation' => [
        'surface' => 'protocol',
        'evidence' => ['kind' => 'rust', 'file' => 'mcp-server/src/mcp.rs', 'name' => 'accepts_cancellation_notifications_without_a_response'],
    ],
    'protocol.retries' => [
        'surface' => 'protocol',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpRemoteTransportTest.php', 'name' => 'remote deployment deduplicates retries with the same request ID'],
    ],
    'protocol.reconnection' => [
        'surface' => 'protocol',
        'gap' => [
            'issue' => 'RUB-380',
            'reason' => 'Nothing tests a transport drop: whether a stale session id is refused, or whether a retry after an interrupted request can produce a second artifact.',
        ],
    ],

    // ── OAuth ─────────────────────────────────────────────────────────────
    'oauth.discovery' => [
        'surface' => 'oauth',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpOAuthTest.php', 'name' => 'publishes MCP authorization metadata'],
    ],
    'oauth.pkce' => [
        'surface' => 'oauth',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpOAuthTest.php', 'name' => 'a registered public client can complete the PKCE authorization flow'],
    ],
    'oauth.consent' => [
        'surface' => 'oauth',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpOAuthTest.php', 'name' => 'approves a PKCE request and redeems its code once'],
    ],
    'oauth.refresh' => [
        'surface' => 'oauth',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpSecurityMatrixTest.php', 'name' => 'a refresh token rotates and replaying the old one revokes the connection'],
    ],
    'oauth.revocation' => [
        'surface' => 'oauth',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpSecurityMatrixTest.php', 'name' => 'revoking the refresh token stops the access token and the refresh path'],
    ],
    'oauth.scope_enforcement' => [
        'surface' => 'oauth',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpRemoteTransportTest.php', 'name' => 'remote MCP tools honor bearer scopes'],
    ],
    'oauth.redirect_uri_hardening' => [
        'surface' => 'oauth',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpOAuthTest.php', 'name' => 'rejects a redirect URI that PHP and browsers parse differently'],
    ],

    // ── Tenancy ───────────────────────────────────────────────────────────
    'tenancy.isolation_units' => [
        'surface' => 'tenancy',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpSecurityMatrixTest.php', 'name' => 'two organizations never see each others MCP activity or connections'],
    ],
    'tenancy.isolation_live' => [
        'surface' => 'tenancy',
        'evidence' => ['kind' => 'marker', 'file' => 'scripts/mcp-live-smoke.mjs', 'name' => 'tenantIsolation'],
    ],

    // ── Policy, quota, degraded states ────────────────────────────────────
    'policy.rate_limits' => [
        'surface' => 'policy',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpRemoteTransportTest.php', 'name' => 'remote MCP rate limits each credential and returns retry guidance'],
    ],
    'policy.quota' => [
        'surface' => 'policy',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpRemoteTransportTest.php', 'name' => 'remote usage returns customer-safe quota and render totals'],
    ],
    'policy.degraded_service' => [
        'surface' => 'policy',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpRemoteTransportTest.php', 'name' => 'remote deployment reports an unavailable artifact service instead of an internal error'],
    ],

    // ── Clients ───────────────────────────────────────────────────────────
    'clients.agent_discovery' => [
        'surface' => 'clients',
        'evidence' => ['kind' => 'rust', 'file' => 'mcp-server/src/setup.rs', 'name' => 'discovers_known_agents'],
    ],
    'clients.config_writers' => [
        'surface' => 'clients',
        'evidence' => ['kind' => 'rust', 'file' => 'mcp-server/src/setup.rs', 'name' => 'setup_writes_host_flag_for_each_agent'],
    ],
    'clients.live_compatibility' => [
        'surface' => 'clients',
        'gap' => [
            'issue' => 'RUB-383',
            'reason' => 'Requires each third-party client binary and its own interactive consent; faking it would test the fake, so it is a per-release manual runbook that also carries the known-incompatibility documentation clause.',
        ],
    ],

    // ── Onboarding surface ────────────────────────────────────────────────
    'ui.connection_onboarding' => [
        'surface' => 'ui',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Browser/McpConnectionsBrowserTest.php', 'name' => 'team members can inspect setup details and revoke an MCP connection'],
    ],

    // ── Robustness ────────────────────────────────────────────────────────
    'robustness.malformed_input' => [
        'surface' => 'robustness',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpSecurityMatrixTest.php', 'name' => 'malformed JSON-RPC bodies never cause a server error'],
    ],
    'robustness.fuzz_property' => [
        'surface' => 'robustness',
        'gap' => [
            'issue' => 'RUB-382',
            'reason' => 'Only hand-written example inputs exist; no generated-input test proves that no envelope produces a 5xx or a panic.',
        ],
    ],
    'robustness.secret_persistence' => [
        'surface' => 'robustness',
        'evidence' => ['kind' => 'php', 'file' => 'tests/Feature/McpSecurityMatrixTest.php', 'name' => 'no bearer credential is persisted in activity, connections or refresh tokens'],
    ],
    'robustness.secret_redaction' => [
        'surface' => 'robustness',
        'gap' => [
            'issue' => 'RUB-381',
            'reason' => 'Storage and error bodies are covered, but nothing asserts a credential is absent from logs, traces, or the config files the CLI writes.',
        ],
    ],

    // ── Load ──────────────────────────────────────────────────────────────
    'load.concurrent_sessions' => [
        'surface' => 'load',
        'evidence' => ['kind' => 'marker', 'file' => 'scripts/mcp-live-smoke.mjs', 'name' => 'MCP_LIVE_CONCURRENCY'],
    ],
];
