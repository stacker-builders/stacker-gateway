<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpOauthClient extends Model
{
    protected $fillable = [
        'client_id',
        'client_name',
        'redirect_uris',
        'token_endpoint_auth_method',
    ];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
        ];
    }

    public function allowsRedirect(string $uri): bool
    {
        $uri = trim($uri);
        $allowed = $this->redirect_uris;
        if (! is_array($allowed)) {
            return false;
        }

        return in_array($uri, array_map('strval', $allowed), true);
    }
}
