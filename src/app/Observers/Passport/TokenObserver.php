<?php

namespace App\Observers\Passport;

use App\Auth\PassportClient;
use Laravel\Passport\Token;

class TokenObserver
{
    public function creating(Token $token): void
    {
        /** @var PassportClient */
        $client = $token->client;
        $scopes = $token->scopes;
        if ($scopes) {
            $allowedScopes = $client->getAllowedScopes();
            if (!empty($allowedScopes)) {
                $scopes = array_intersect($scopes, $allowedScopes);
            }
            $scopes = array_values(array_unique($scopes));
            $token->scopes = $scopes;
        }
    }
}
