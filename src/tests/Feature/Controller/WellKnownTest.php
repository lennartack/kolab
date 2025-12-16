<?php

namespace Tests\Feature\Controller;

use App\Utils;
use Tests\TestCase;

class WellKnownTest extends TestCase
{
    /**
     * Test ./well-known/openid-configuration
     */
    public function testOpenidConfiguration(): void
    {
        $href = Utils::serviceUrl('/');

        // HTTP_HOST is not set in tests for some reason, but it's required down the line
        $host = parse_url($href, \PHP_URL_HOST);
        $_SERVER['HTTP_HOST'] = $host;

        $response = $this->get('.well-known/openid-configuration');
        $response->assertStatus(200)
            ->assertJson([
                'issuer' => $href,
                'authorization_endpoint' => $href . '/oauth/authorize',
                'token_endpoint' => $href . '/oauth/token',
                'userinfo_endpoint' => $href . '/oauth/userinfo',
                'grant_types_supported' => [
                    'authorization_code',
                    'client_credentials',
                    'refresh_token',
                    'password',
                ],
                'response_types_supported' => [
                    'code',
                ],
                'id_token_signing_alg_values_supported' => [
                    'RS256',
                ],
                'scopes_supported' => [
                    'openid',
                    'email',
                ],
            ]);
    }

    /**
     * Test ./well-known/mta-sts.txt
     */
    public function testMtaSts(): void
    {
        $domain = \config('app.domain');

        $response = $this->get('.well-known/mta-sts.txt');
        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertContent("version: STSv1\nmode: enforce\nmx: {$domain}\nmax_age: 604800");
    }
}
