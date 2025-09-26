<?php

namespace App\Auth;

use App\User;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use OpenIDConnect\Claims\Traits\WithClaims;
use OpenIDConnect\Interfaces\IdentityEntityInterface;

class IdentityEntity implements IdentityEntityInterface
{
    use EntityTrait;
    use WithClaims;

    /**
     * The user to collect the additional information for
     */
    protected User $user;

    /**
     * The identity repository creates this entity and provides the user id
     *
     * @param mixed $identifier
     */
    public function setIdentifier($identifier): void
    {
        $this->identifier = $identifier;
        $this->user = User::findOrFail($identifier);
    }

    /**
     * When building the id_token, this entity's claims are collected
     *
     * @param string[] $scopes Optional scope filter
     */
    public function getClaims(array $scopes = []): array
    {
        $claims = [];

        if (in_array('email', $scopes)) {
            $claims['email'] = $this->user->email;
        }

        // Short living password for IMAP/SMTP
        // We use same TTL as for the OAuth tokens, so clients can get a new password on token refresh
        if (in_array('auth.token', $scopes)) {
            $ttl = config('auth.token_expiry_minutes') * 60;
            $claims['auth.token'] = Utils::tokenCreate((string) $this->user->id, $ttl);
        }

        // A custom scope/claim that allows us to use an old user identifier (e.g. after a migration from an old system)
        if (in_array('uuid', $scopes)) {
            $claims['uuid'] = (string) ($this->user->getSetting('uid') ?: $this->user->id);
        }

        return $claims;
    }
}
