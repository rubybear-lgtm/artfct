<?php

use App\Http\Controllers\OAuth\AuthorizationServerController;
use Illuminate\Support\Facades\Route;

Route::get('.well-known/oauth-authorization-server', [AuthorizationServerController::class, 'authorizationServerMetadata'])
    ->name('oauth.metadata');
Route::get('.well-known/oauth-protected-resource', [AuthorizationServerController::class, 'protectedResourceMetadata'])
    ->name('oauth.resource-metadata');
Route::get('.well-known/oauth-protected-resource/{path}', [AuthorizationServerController::class, 'protectedResourceMetadata'])
    ->where('path', 'mcp')
    ->name('mcp.oauth.protected-resource.nested');

Route::post('oauth/register', [AuthorizationServerController::class, 'register'])
    ->middleware('throttle:oauth-registration')
    ->name('oauth.register');

Route::get('oauth/authorize', [AuthorizationServerController::class, 'authorize'])
    ->middleware('throttle:auth')
    ->name('oauth.authorize');
Route::post('oauth/authorize', [AuthorizationServerController::class, 'approve'])
    ->middleware(['auth', 'throttle:auth'])
    ->name('oauth.authorize.approve');
Route::post('oauth/token', [AuthorizationServerController::class, 'token'])
    ->middleware('throttle:auth')
    ->name('oauth.token');
Route::post('oauth/revoke', [AuthorizationServerController::class, 'revoke'])
    ->middleware('throttle:auth')
    ->name('oauth.revoke');
Route::get('oauth/organizations', [AuthorizationServerController::class, 'organizations'])
    ->middleware('throttle:auth')
    ->name('oauth.organizations');
