<?php

use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Slack\SlashCommandController;
use App\Http\Middleware\AuthenticateOrgToken;
use App\Http\Middleware\VerifySlackSignature;
use Illuminate\Support\Facades\Route;

/**
 * Spec 13: `search_artifacts`'s backing endpoint. Authenticated by an org
 * JWT bearer token (AuthenticateOrgToken), never by web session — this is
 * the surface the MCP server and the console's search both call.
 */
Route::middleware(AuthenticateOrgToken::class)->group(function () {
    Route::post('search', [SearchController::class, 'search'])->name('api.search');
});

Route::middleware(VerifySlackSignature::class)->group(function () {
    Route::post('slack/commands', [SlashCommandController::class, 'handle'])->name('api.slack.commands');
});
