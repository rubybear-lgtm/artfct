<?php

namespace App\Models;

use Database\Factories\OAuthClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<int, string> $redirect_uris
 * @property array<int, string>|null $grant_types
 * @property array<int, string>|null $response_types
 */
#[Fillable([
    'client_id',
    'client_name',
    'redirect_uris',
    'grant_types',
    'response_types',
    'token_endpoint_auth_method',
    'client_id_issued_at',
])]
class OAuthClient extends Model
{
    /** @use HasFactory<OAuthClientFactory> */
    use HasFactory;

    protected $table = 'oauth_clients';

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'grant_types' => 'array',
            'response_types' => 'array',
            'client_id_issued_at' => 'integer',
        ];
    }
}
