<?php

namespace Tests\Feature\Controller;

use App\Domain;
use App\IP4Net;
use App\Policy\Greylist;
use Carbon\Carbon;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    private $clientAddress;
    private $net;
    private $testUser;
    private $testDomain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientAddress = '128.0.0.100';

        $this->net = IP4Net::create([
            'net_number' => '128.0.0.0',
            'net_broadcast' => '128.255.255.255',
            'net_mask' => 8,
            'country' => 'US',
            'rir_name' => 'test',
            'serial' => 1,
        ]);

        $this->testDomain = $this->getTestDomain('test.domain', [
            'type' => Domain::TYPE_EXTERNAL,
            'status' => Domain::STATUS_ACTIVE | Domain::STATUS_CONFIRMED | Domain::STATUS_VERIFIED,
        ]);

        $this->testUser = $this->getTestUser('john@test.domain');

        Greylist\Connect::where('sender_domain', 'sender.domain')->delete();
        Greylist\Whitelist::where('sender_domain', 'sender.domain')->delete();

        $this->useServicesUrl();
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser($this->testUser->email);
        $this->deleteTestDomain($this->testDomain->namespace);
        $this->net->delete();

        Greylist\Connect::where('sender_domain', 'sender.domain')->delete();
        Greylist\Whitelist::where('sender_domain', 'sender.domain')->delete();

        $john = $this->getTestUser('john@kolab.org');
        $john->settings()
            ->whereIn('key', ['password_policy', 'max_password_age', 'itip_policy', 'externalsender_policy'])->delete();

        parent::tearDown();
    }

    /**
     * Test password policy check
     */
    public function testCheckPassword(): void
    {
        $this->useRegularUrl();

        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $john->setSetting('password_policy', 'min:8,max:100,upper,digit');

        // Empty password
        $post = ['user' => $john->id];
        $response = $this->post('/api/auth/password-policy-check', $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(3, $json);
        $this->assertSame('error', $json['status']);
        $this->assertSame(4, $json['count']);
        $this->assertFalse($json['list'][0]['status']);
        $this->assertSame('min', $json['list'][0]['label']);
        $this->assertFalse($json['list'][1]['status']);
        $this->assertSame('max', $json['list'][1]['label']);
        $this->assertFalse($json['list'][2]['status']);
        $this->assertSame('upper', $json['list'][2]['label']);
        $this->assertFalse($json['list'][3]['status']);
        $this->assertSame('digit', $json['list'][3]['label']);

        // Test acting as Jack, password non-compliant
        $post = ['password' => '9999999', 'user' => $jack->id];
        $response = $this->post('/api/auth/password-policy-check', $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(3, $json);
        $this->assertSame('error', $json['status']);
        $this->assertSame(4, $json['count']);
        $this->assertFalse($json['list'][0]['status']); // min
        $this->assertTrue($json['list'][1]['status']); // max
        $this->assertFalse($json['list'][2]['status']); // upper
        $this->assertTrue($json['list'][3]['status']); // digit

        // Test with no user context, expect use of the default policy
        $post = ['password' => '9'];
        $response = $this->post('/api/auth/password-policy-check', $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(3, $json);
        $this->assertSame('error', $json['status']);
        $this->assertSame(2, $json['count']);
        $this->assertFalse($json['list'][0]['status']);
        $this->assertSame('min', $json['list'][0]['label']);
        $this->assertTrue($json['list'][1]['status']);
        $this->assertSame('max', $json['list'][1]['label']);
    }

    /**
     * Test greylist policy webhook
     */
    public function testGreylist()
    {
        // Note: Only basic tests here. More detailed policy handler tests are in another place

        // Test 403 response
        $post = [
            'sender' => 'someone@sender.domain',
            'recipient' => $this->testUser->email,
            'client_address' => $this->clientAddress,
            'client_name' => 'some.mx',
        ];

        $response = $this->post('/api/webhooks/policy/greylist', $post);

        $response->assertStatus(403);

        $json = $response->json();

        $this->assertSame('DEFER_IF_PERMIT', $json['response']);
        $this->assertSame("Greylisted for 5 minutes. Try again later.", $json['reason']);

        // Test 200 response
        $connect = Greylist\Connect::where('sender_domain', 'sender.domain')->first();
        $connect->created_at = Carbon::now()->subMinutes(6);
        $connect->save();

        $response = $this->post('/api/webhooks/policy/greylist', $post);

        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('DUNNO', $json['response']);
        $this->assertMatchesRegularExpression('/^Received-Greylist: greylisted from/', $json['prepend'][0]);
    }

    /**
     * Test fetching account 'password' policies
     */
    public function testIndexPassword(): void
    {
        $this->useRegularUrl();

        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $john->setSetting('password_policy', 'min:8,max:255,special');
        $john->setSetting('max_password_age', 6);

        // Unauth access not allowed
        $response = $this->get('/api/v4/policies');
        $response->assertStatus(401);

        // Test acting as non-controller
        $response = $this->actingAs($jack)->get('/api/v4/policies');
        $response->assertStatus(403);

        // Get available policy rules
        $response = $this->actingAs($john)->get('/api/v4/policies');
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertCount(7, $json['password']);
        $this->assertSame('6', $json['config']['max_password_age']);
        $this->assertSame('Minimum password length: 8 characters', $json['password'][0]['name']);
        $this->assertSame('min', $json['password'][0]['label']);
        $this->assertSame('8', $json['password'][0]['param']);
        $this->assertTrue($json['password'][0]['enabled']);
        $this->assertSame('Maximum password length: 255 characters', $json['password'][1]['name']);
        $this->assertSame('max', $json['password'][1]['label']);
        $this->assertSame('255', $json['password'][1]['param']);
        $this->assertTrue($json['password'][1]['enabled']);
        $this->assertSame('lower', $json['password'][2]['label']);
        $this->assertFalse($json['password'][2]['enabled']);
        $this->assertSame('upper', $json['password'][3]['label']);
        $this->assertFalse($json['password'][3]['enabled']);
        $this->assertSame('digit', $json['password'][4]['label']);
        $this->assertFalse($json['password'][4]['enabled']);
        $this->assertSame('special', $json['password'][5]['label']);
        $this->assertTrue($json['password'][5]['enabled']);
        $this->assertSame('last', $json['password'][6]['label']);
        $this->assertFalse($json['password'][6]['enabled']);
    }

    /**
     * Test fetching account 'mailDelivery' policies
     */
    public function testIndexMailDelivery(): void
    {
        $this->useRegularUrl();

        $keys = ['greylist_policy', 'itip_policy', 'externalsender_policy', 'externalsender_policy_domains'];

        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $john->settings()->whereIn('key', $keys)->delete();

        // Unauth access not allowed
        $response = $this->get('/api/v4/policies');
        $response->assertStatus(401);

        // Test acting as non-controller
        $response = $this->actingAs($jack)->get('/api/v4/policies');
        $response->assertStatus(403);

        // Get polcies when mailfilter is disabled
        \config(['app.with_mailfilter' => false]);
        $response = $this->actingAs($john)->get('/api/v4/policies');
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertSame(['greylist_policy'], $json['mailDelivery']);
        $this->assertTrue($json['config']['greylist_policy']);

        // Get polcies when mailfilter is enabled
        \config(['app.with_mailfilter' => true]);
        $john->setConfig(['externalsender_policy' => true]);
        $response = $this->actingAs($john)->get('/api/v4/policies');
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertSame($keys, $json['mailDelivery']);
        $this->assertFalse($json['config']['itip_policy']);
        $this->assertTrue($json['config']['greylist_policy']);
        $this->assertTrue($json['config']['externalsender_policy']);
        $this->assertSame([], $json['config']['externalsender_policy_domains']);
    }

    /**
     * Test mail filter (POST /api/webhooks/policy/mail/filter)
     */
    public function testMailfilter()
    {
        // Note: Only basic tests here. More detailed policy handler tests are in another place

        $headers = ['CONTENT_TYPE' => 'message/rfc822'];
        $post = file_get_contents(self::BASE_DIR . '/data/mail/1.eml');
        $post = str_replace("\n", "\r\n", $post);

        $john = $this->getTestUser('john@kolab.org');

        // Basic test, no changes to the mail content
        $url = '/api/webhooks/policy/mail/filter?recipient=john@kolab.org&sender=jack@kolab.org';
        $response = $this->call('POST', $url, [], [], [], $headers, $post)
            ->assertNoContent(204);

        // Test returning (modified) mail content
        $john->setConfig(['externalsender_policy' => true, 'itip_policy' => true]);
        $url = '/api/webhooks/policy/mail/filter?recipient=john@kolab.org&sender=jack@external.tld';
        $content = $this->call('POST', $url, [], [], [], $headers, $post)
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'message/rfc822')
            ->streamedContent();

        $this->assertStringContainsString('Subject: [EXTERNAL] test sync', $content);
    }

    /**
     * Test submission policy webhook
     */
    public function testSubmission()
    {
        // Note: Only basic tests here. More detailed policy handler tests are in another place

        // Test invalid sender
        $post = [
            'sender' => 'sender',
            'recipients' => ['recipient@gmail.com'],
        ];

        $response = $this->post('/api/webhooks/policy/submission', $post);
        $response->assertStatus(403);

        $json = $response->json();

        $this->assertSame('REJECT', $json['response']);
        $this->assertSame("Invalid sender", $json['reason']);

        // Test invalid user
        $post = [
            'user' => 'unknown',
            'sender' => $this->testUser->email,
            'recipients' => ['recipient@gmail.com'],
        ];

        $response = $this->post('/api/webhooks/policy/submission', $post);
        $response->assertStatus(403);

        $json = $response->json();

        $this->assertSame('REJECT', $json['response']);
        $this->assertSame("Invalid user", $json['reason']);

        // Test unknown user
        $post = [
            'user' => 'unknown@domain.tld',
            'sender' => 'john+test@test.domain',
            'recipients' => ['recipient@gmail.com'],
        ];

        $response = $this->post('/api/webhooks/policy/submission', $post);
        $response->assertStatus(403);

        $json = $response->json();

        $this->assertSame('REJECT', $json['response']);
        $this->assertSame("Could not find user {$post['user']}", $json['reason']);

        // Test existing user and an invalid sender address
        $post = [
            'user' => 'john@test.domain',
            'sender' => 'john1@test.domain',
            'recipients' => ['recipient@gmail.com'],
        ];

        $response = $this->post('/api/webhooks/policy/submission', $post);
        $response->assertStatus(403);

        $json = $response->json();

        $this->assertSame('REJECT', $json['response']);
        $this->assertSame("john@test.domain is unauthorized to send mail as john1@test.domain", $json['reason']);

        // Test existing user with a valid sender address
        $post = [
            'user' => 'john@test.domain',
            'sender' => 'john+test@test.domain',
            'recipients' => ['recipient@gmail.com'],
        ];

        $response = $this->post('/api/webhooks/policy/submission', $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('DUNNO', $json['response']);
    }

    /**
     * Test ratelimit policy webhook
     */
    public function testRatelimit()
    {
        // Note: Only basic tests here. More detailed policy handler tests are in another place

        // Test a valid user
        $post = [
            'sender' => $this->testUser->email,
            'recipients' => 'someone@sender.domain',
        ];

        $response = $this->post('/api/webhooks/policy/ratelimit', $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('DUNNO', $json['response']);

        // Test invalid sender
        $post['sender'] = 'non-existing';
        $response = $this->post('/api/webhooks/policy/ratelimit', $post);
        $response->assertStatus(403);

        $json = $response->json();

        $this->assertSame('HOLD', $json['response']);
        $this->assertSame('Invalid sender email', $json['reason']);

        // Test unknown sender
        $post['sender'] = 'non-existing@example.com';
        $response = $this->post('/api/webhooks/policy/ratelimit', $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('DUNNO', $json['response']);

        // Test alias sender
        $this->testUser->suspend();
        $this->testUser->aliases()->create(['alias' => 'alias@test.domain']);
        $post['sender'] = 'alias@test.domain';
        $response = $this->post('/api/webhooks/policy/ratelimit', $post);
        $response->assertStatus(403);

        $json = $response->json();

        $this->assertSame('HOLD', $json['response']);
        $this->assertSame('Sender deleted or suspended', $json['reason']);

        // Test app.ratelimit_whitelist
        \config(['app.ratelimit_whitelist' => ['alias@test.domain']]);
        $response = $this->post('/api/webhooks/policy/ratelimit', $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('DUNNO', $json['response']);
    }

    /**
     * Test SPF webhook
     *
     * @group data
     * @group skipci
     */
    public function testSenderPolicyFramework(): void
    {
        // Note: Only basic tests here. More detailed policy handler tests are in another place

        // TODO: Make a test that does not depend on data/dns (remove skipci)

        // Test a valid user
        $post = [
            'instance' => 'test.local.instance',
            'protocol_state' => 'RCPT',
            'sender' => 'sender@spf-fail.kolab.org',
            'client_name' => 'mx.kolabnow.com',
            'client_address' => '212.103.80.148',
            'recipient' => $this->testUser->email,
        ];

        $response = $this->post('/api/webhooks/policy/spf', $post);
        $response->assertStatus(403);

        $json = $response->json();

        $this->assertSame('REJECT', $json['response']);
        $this->assertSame('Prohibited by Sender Policy Framework', $json['reason']);
        $this->assertSame(['Received-SPF: Fail identity=mailfrom; client-ip=212.103.80.148;'
            . ' helo=mx.kolabnow.com; envelope-from=sender@spf-fail.kolab.org;'], $json['prepend']);
    }
}
