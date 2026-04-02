<?php

namespace Tests\Browser;

use App\Auth\PassportClient;
use App\Utils;
use Illuminate\Support\Facades\Cache;
use Tests\Browser;
use Tests\Browser\Pages\Home;
use Tests\TestCaseDusk;

class AuthorizeTest extends TestCaseDusk
{
    private $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a client for tests
        $user = $this->getTestUser('john@kolab.org');
        $this->client = PassportClient::firstOrCreate(
            ['id' => 'test' . base64_encode(random_bytes(4))],
            [
                'user_id' => $user->id,
                'name' => 'Test',
                'secret' => '123',
                'provider' => 'users',
                'redirect' => Utils::serviceUrl('support'),
                'personal_access_client' => 0,
                'password_client' => 0,
                'revoked' => false,
                'allowed_scopes' => ['email', 'auth.token'],
            ]
        );
    }

    protected function tearDown(): void
    {
        $this->client->delete();

        parent::tearDown();
    }

    /**
     * Test /oauth/authorize page
     */
    public function testAuthorize(): void
    {
        $user = $this->getTestUser('john@kolab.org');

        $url = '/oauth/authorize?' . http_build_query([
            'client_id' => $this->client->id,
            'response_type' => 'code',
            'scope' => 'email auth.token',
            'state' => 'state',
            'redirect_uri' => $this->client->redirect,
        ]);

        Cache::forget("oauth-seen-{$user->id}-{$this->client->id}");

        $this->browse(function (Browser $browser) use ($url, $user) {
            // Visit the page and expect logon form, then log in
            $browser->visit($url)
                ->on(new Home())
                ->submitLogon($user->email, 'simple123');

            // Expect the claims form
            $browser->waitFor('#auth-form')
                ->assertSeeIn('#auth-form h1', "Test is asking for permission")
                ->assertSeeIn('#auth-email', $user->email)
                ->assertVisible('#auth-header')
                ->assertElementsCount('#auth-claims li', 2)
                ->assertSeeIn('#auth-claims li:nth-child(1)', "See your email address")
                ->assertSeeIn('#auth-claims li:nth-child(2)', "Have read and write access to")
                ->assertSeeIn('#auth-footer', $this->client->redirect)
                ->assertSeeIn('#auth-form button.btn-success', 'Allow access')
                ->assertSeeIn('#auth-form button.btn-danger', 'No, thanks');

            // Click the "No, thanks" button
            $browser->click('#auth-form button.btn-danger')
                ->waitForLocation('/support')
                ->assertScript("location.search.match(/^\\?error=access_denied&state=state/) !== null");

            // Visit the page again and click the "Allow access" button
            $browser->visit($url)
                ->waitFor('#auth-form button.btn-success')
                ->click('#auth-form button.btn-success')
                ->waitForLocation('/support')
                ->assertScript("location.search.match(/^\\?code=[a-f0-9]+&state=state/) !== null")
                ->pause(1000); // let the Support page refresh the session tokens before we proceed

            // Visit the page and expect an immediate redirect
            $browser->visit($url)
                ->waitForLocation('/support')
                ->assertScript("location.search.match(/^\\?code=[a-f0-9]+&state=state/) !== null")
                ->pause(1000); // let the Support page refresh the session token before we proceed

            // Error handling (invalid response_type)
            $browser->visit(str_replace('response_type=code', 'response_type=invalid', $url))
                ->waitForLocation('/support')
                ->assertScript("location.search.match(/^\\?error=unsupported_response_type&state=state/) !== null");
        });
    }
}
