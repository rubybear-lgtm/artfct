<?php

use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\McpConnectionController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Slack\SlashCommandController;
use App\Http\Middleware\AuthenticateOrgToken;
use App\Http\Middleware\McpRateLimit;
use App\Http\Middleware\VerifySlackSignature;
use Illuminate\Support\Facades\Route;

/**
 * Spec 13: `search_artifacts`'s backing endpoint. Authenticated by an org
 * JWT bearer token (AuthenticateOrgToken), never by web session — this is
 * the surface the MCP server and the console's search both call.
 */
Route::middleware([
    AuthenticateOrgToken::class,
    McpRateLimit::class,
])->group(function () {
    Route::post('search', [SearchController::class, 'search'])->name('api.search');
    Route::get('collections', [CollectionController::class, 'index'])->name('api.collections.index');
    Route::post('collections', [CollectionController::class, 'store'])->name('api.collections.store');
    Route::post('collections/{collection}/artifacts', [CollectionController::class, 'addArtifact'])->name('api.collections.artifacts.add');
    Route::post('mcp/connections', [McpConnectionController::class, 'register'])->name('api.mcp.connections.register');
    Route::post('mcp/connections/heartbeat', [McpConnectionController::class, 'heartbeat'])->name('api.mcp.connections.heartbeat');
});

Route::middleware(VerifySlackSignature::class)->group(function () {
    Route::post('slack/commands', [SlashCommandController::class, 'handle'])->name('api.slack.commands');
});
