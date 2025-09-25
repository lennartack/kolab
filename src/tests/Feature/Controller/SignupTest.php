<?php

namespace Tests\Feature\Controller;

use App\Discount;
use App\Domain;
use App\Http\Controllers\API\SignupController;
use App\IP4Net;
use App\Jobs\Mail\SignupVerificationJob;
use App\Jobs\User\CreateJob;
use App\Package;
use App\Plan;
use App\ReferralProgram;
use App\SignupCode;
use App\SignupInvitation as SI;
use App\SignupToken;
use App\User;
use App\VatRate;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SignupTest extends TestCase
{
    private $domain;

    protected function setUp(): void
    {
        parent::setUp();

        // TODO: Some tests depend on existence of individual and group plans,
        //       we should probably create plans here to not depend on that
        $this->domain = $this->getPublicDomain();

        $this->deleteTestUser("SignupControllerTest1@{$this->domain}");
        $this->deleteTestUser("signuplogin@{$this->domain}");
        $this->deleteTestUser("admin@external.com");
        $this->deleteTestUser("test-inv@kolabnow.com");

        $this->deleteTestDomain('external.com');
        $this->deleteTestDomain('signup-domain.com');

        $this->deleteTestGroup('group-test@kolabnow.com');

        SI::truncate();
        SignupToken::truncate();
        Plan::where('title', 'test')->delete();
        IP4Net::where('net_number', inet_pton('128.0.0.0'))->delete();
        VatRate::query()->delete();
        ReferralProgram::query()->delete();
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser("SignupControllerTest1@{$this->domain}");
        $this->deleteTestUser("signuplogin@{$this->domain}");
        $this->deleteTestUser("admin@external.com");
        $this->deleteTestUser("test-inv@kolabnow.com");

        $this->deleteTestDomain('external.com');
        $this->deleteTestDomain('signup-domain.com');

        $this->deleteTestGroup('group-test@kolabnow.com');

        SI::truncate();
        SignupToken::truncate();
        Plan::where('title', 'test')->delete();
        IP4Net::where('net_number', inet_pton('128.0.0.0'))->delete();
        VatRate::query()->delete();
        ReferralProgram::query()->delete();

        parent::tearDown();
    }

    /**
     * Return a public domain for signup tests
     */
    private function getPublicDomain(): string
    {
        if (!$this->domain) {
            $this->refreshApplication();
            $public_domains = Domain::getPublicDomains();
            $this->domain = reset($public_domains);

            if (empty($this->domain)) {
                $this->domain = 'signup-domain.com';
                Domain::create([
                    'namespace' => $this->domain,
                    'status' => Domain::STATUS_ACTIVE,
                    'type' => Domain::TYPE_PUBLIC,
                ]);
            }
        }

        return $this->domain;
    }

    /**
     * Test fetching public domains for signup
     */
    public function testSignupDomains(): void
    {
        $response = $this->get('/api/auth/signup/domains');
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame(Domain::getPublicDomains(), $json['domains']);
    }

    /**
     * Test fetching plans for signup
     */
    public function testSignupPlans(): void
    {
        $individual = Plan::withEnvTenantContext()->where('title', 'individual')->first();
        $group = Plan::withEnvTenantContext()->where('title', 'group')->first();
        $hidden = Plan::create([
            'title' => 'test',
            'name' => 'Test Account',
            'description' => 'Test',
            'hidden' => true,
            'mode' => Plan::MODE_MANDATE,
        ]);

        $response = $this->get('/api/auth/signup/plans');
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertSame('success', $json['status']);
        $this->assertCount(2, $json['plans']);
        $this->assertSame($individual->title, $json['plans'][0]['title']);
        $this->assertSame($individual->name, $json['plans'][0]['name']);
        $this->assertSame($individual->description, $json['plans'][0]['description']);
        $this->assertSame(990, $json['plans'][0]['cost']);
        $this->assertFalse($json['plans'][0]['isDomain']);
        $this->assertArrayHasKey('button', $json['plans'][0]);
        $this->assertSame($group->title, $json['plans'][1]['title']);
        $this->assertSame($group->name, $json['plans'][1]['name']);
        $this->assertSame($group->description, $json['plans'][1]['description']);
        $this->assertSame(990, $json['plans'][1]['cost']);
        $this->assertTrue($json['plans'][1]['isDomain']);
        $this->assertArrayHasKey('button', $json['plans'][1]);
    }

    /**
     * Test fetching invitation
     */
    public function testSignupInvitations(): void
    {
        Queue::fake();

        $invitation = SI::create(['email' => 'email1@ext.com']);

        // Test existing invitation
        $response = $this->get("/api/auth/signup/invitations/{$invitation->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($invitation->id, $json['id']);

        // Test non-existing invitation
        $response = $this->get("/api/auth/signup/invitations/abc");
        $response->assertStatus(404);

        // Test completed invitation
        SI::where('id', $invitation->id)->update(['status' => SI::STATUS_COMPLETED]);
        $response = $this->get("/api/auth/signup/invitations/{$invitation->id}");
        $response->assertStatus(404);
    }

    /**
     * Test signup initialization with invalid input
     */
    public function testSignupInitInvalidInput(): void
    {
        // Empty input data
        $data = [];

        $response = $this->post('/api/auth/signup/init', $data);
        $json = $response->json();

        $response->assertStatus(422);

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']);
        $this->assertArrayHasKey('email', $json['errors']);
        $this->assertArrayHasKey('plan', $json['errors']);

        // Data with missing name
        $data = [
            'email' => 'UsersApiControllerTest1@UsersApiControllerTest.com',
            'first_name' => str_repeat('a', 250),
            'last_name' => str_repeat('a', 250),
            'plan' => 'individual',
        ];

        $response = $this->post('/api/auth/signup/init', $data);
        $json = $response->json();

        $response->assertStatus(422);

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']);
        $this->assertArrayHasKey('first_name', $json['errors']);
        $this->assertArrayHasKey('last_name', $json['errors']);

        // Data with names containing URLs
        $data = [
            'email' => 'UsersApiControllerTest1@UsersApiControllerTest.com',
            'first_name' => 'BTC http://test.com Test',
            'last_name' => 'http://test.com',
            'plan' => 'individual',
        ];

        $response = $this->post('/api/auth/signup/init', $data);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']);
        $this->assertArrayHasKey('first_name', $json['errors']);
        $this->assertArrayHasKey('last_name', $json['errors']);
        $this->assertSame(['Invalid value'], $json['errors']['first_name']);
        $this->assertSame(['Invalid value'], $json['errors']['last_name']);

        // Data with invalid email (but not phone number), and invalid plan
        $data = [
            'email' => '@example.org',
            'first_name' => 'Signup',
            'last_name' => 'User',
            'plan' => 'invalid',
        ];

        $response = $this->post('/api/auth/signup/init', $data);
        $json = $response->json();

        $response->assertStatus(422);

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']);
        $this->assertArrayHasKey('email', $json['errors']);
        $this->assertArrayHasKey('plan', $json['errors']);

        // Sanity check on voucher code, last/first name is optional
        $data = [
            'voucher' => '123456789012345678901234567890123',
            'email' => 'valid@email.com',
            'plan' => 'individual',
        ];

        $response = $this->post('/api/auth/signup/init', $data);
        $json = $response->json();

        $response->assertStatus(422);

        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertArrayHasKey('voucher', $json['errors']);

        // Email address too long
        $data = [
            'email' => str_repeat('a', 190) . '@example.org',
            'first_name' => 'Signup',
            'last_name' => 'User',
            'plan' => 'individual',
        ];

        $response = $this->post('/api/auth/signup/init', $data);
        $json = $response->json();

        $response->assertStatus(422);

        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertSame(["The specified email address is invalid."], $json['errors']['email']);

        SignupCode::truncate();

        // Email address limit check
        $data = [
            'email' => 'test@example.org',
            'first_name' => 'Signup',
            'last_name' => 'User',
            'plan' => 'individual',
        ];

        \config(['app.signup.email_limit' => 0]);

        $response = $this->post('/api/auth/signup/init', $data);
        $json = $response->json();

        $response->assertStatus(200);

        \config(['app.signup.email_limit' => 1]);

        $response = $this->post('/api/auth/signup/init', $data);
        $json = $response->json();

        $response->assertStatus(422);
        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        // TODO: This probably should be a different message?
        $this->assertSame(["The specified email address is invalid."], $json['errors']['email']);

        // IP address limit check
        $data = [
            'email' => 'ip@example.org',
            'first_name' => 'Signup',
            'last_name' => 'User',
            'plan' => 'individual',
        ];

        \config(['app.signup.email_limit' => 0]);
        \config(['app.signup.ip_limit' => 0]);

        $response = $this->post('/api/auth/signup/init', $data, ['REMOTE_ADDR' => '10.1.1.1']);
        $json = $response->json();

        $response->assertStatus(200);

        \config(['app.signup.ip_limit' => 1]);

        $response = $this->post('/api/auth/signup/init', $data, ['REMOTE_ADDR' => '10.1.1.1']);
        $json = $response->json();

        $response->assertStatus(422);

        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        // TODO: This probably should be a different message?
        $this->assertSame(["The specified email address is invalid."], $json['errors']['email']);

        // TODO: Test phone validation
    }

    /**
     * Test signup initialization with valid input
     */
    public function testSignupInitValidInput(): array
    {
        Queue::fake();

        // Assert that no jobs were pushed...
        Queue::assertNothingPushed();

        $data = [
            'email' => 'testuser@external.com',
            'first_name' => 'Signup',
            'last_name' => 'User',
            'plan' => 'group',
        ];

        $response = $this->post('/api/auth/signup/init', $data, ['REMOTE_ADDR' => '10.1.1.2']);
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame('email', $json['mode']);
        $this->assertTrue($json['is_domain']);
        $this->assertNotEmpty($json['code']);
        $this->assertSame($all_domains = Domain::getPublicDomains(), $json['domains']);

        $code = SignupCode::find($json['code']);

        $this->assertSame('10.1.1.2', $code->ip_address);
        $this->assertNull($code->verify_ip_address);
        $this->assertNull($code->submit_ip_address);

        // Assert the email sending job was pushed once
        Queue::assertPushed(SignupVerificationJob::class, 1);

        // Assert the job has proper data assigned
        Queue::assertPushed(SignupVerificationJob::class, static function ($job) use ($data, $json) {
            $code = TestCase::getObjectProperty($job, 'code');

            return $code->code === $json['code']
                && $code->plan === $data['plan']
                && $code->email === $data['email']
                && $code->first_name === $data['first_name']
                && $code->last_name === $data['last_name'];
        });

        // Try the same with voucher
        $data['voucher'] = 'TEST';
        $data['plan'] = 'individual';

        $response = $this->post('/api/auth/signup/init', $data);
        $json = $response->json();

        $response->assertStatus(200);
        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame('email', $json['mode']);
        $this->assertFalse($json['is_domain']);
        $this->assertNotEmpty($json['code']);
        $this->assertSame($all_domains, $json['domains']);

        // Assert the job has proper data assigned
        Queue::assertPushed(SignupVerificationJob::class, static function ($job) use ($data, $json) {
            $code = TestCase::getObjectProperty($job, 'code');

            return $code->code === $json['code']
                && $code->plan === $data['plan']
                && $code->email === $data['email']
                && $code->voucher === $data['voucher']
                && $code->first_name === $data['first_name']
                && $code->last_name === $data['last_name'];
        });

        return [
            'code' => $json['code'],
            'email' => $data['email'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'plan' => $data['plan'],
            'voucher' => $data['voucher'],
        ];
    }

    /**
     * Test signup code verification with invalid input
     *
     * @depends testSignupInitValidInput
     */
    public function testSignupVerifyInvalidInput(array $result): void
    {
        // Empty data
        $data = [];

        $response = $this->post('/api/auth/signup/verify', $data);
        $json = $response->json();

        $response->assertStatus(422);
        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']);
        $this->assertArrayHasKey('code', $json['errors']);
        $this->assertArrayHasKey('short_code', $json['errors']);

        // Data with existing code but missing short_code
        $data = [
            'code' => $result['code'],
        ];

        $response = $this->post('/api/auth/signup/verify', $data);
        $json = $response->json();

        $response->assertStatus(422);
        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertArrayHasKey('short_code', $json['errors']);

        // Data with invalid short_code
        $data = [
            'code' => $result['code'],
            'short_code' => 'XXXX',
        ];

        $response = $this->post('/api/auth/signup/verify', $data);
        $json = $response->json();

        $response->assertStatus(422);
        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertArrayHasKey('short_code', $json['errors']);

        // TODO: Test expired code
    }

    /**
     * Test signup code verification with valid input
     *
     * @depends testSignupInitValidInput
     */
    public function testSignupVerifyValidInput(array $result): array
    {
        $code = SignupCode::find($result['code']);
        $code->ip_address = '10.1.1.2';
        $code->save();
        $data = [
            'code' => $code->code,
            'short_code' => $code->short_code,
        ];

        $response = $this->post('/api/auth/signup/verify', $data, ['REMOTE_ADDR' => '10.1.1.3']);
        $json = $response->json();

        $response->assertStatus(200);
        $this->assertCount(6, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame($result['email'], $json['email']);
        $this->assertSame($result['first_name'], $json['first_name']);
        $this->assertSame($result['last_name'], $json['last_name']);
        $this->assertSame($result['voucher'], $json['voucher']);
        $this->assertFalse($json['is_domain']);
        // $this->assertTrue(is_array($json['domains']) && !empty($json['domains']));

        $code->refresh();

        $this->assertSame('10.1.1.2', $code->ip_address);
        $this->assertSame('10.1.1.3', $code->verify_ip_address);
        $this->assertNull($code->submit_ip_address);

        return $result;
    }

    /**
     * Test last signup step with invalid input
     *
     * @depends testSignupVerifyValidInput
     */
    public function testSignupInvalidInput(array $result): void
    {
        // Empty data
        $data = [];

        $response = $this->post('/api/auth/signup', $data);
        $json = $response->json();

        $response->assertStatus(422);
        $this->assertSame('error', $json['status']);
        $this->assertCount(4, $json['errors']);
        $this->assertArrayHasKey('login', $json['errors']);
        $this->assertArrayHasKey('password', $json['errors']);
        $this->assertArrayHasKey('domain', $json['errors']);
        $this->assertArrayHasKey('plan', $json['errors']);

        $domain = $this->getPublicDomain();

        // Passwords do not match and missing domain
        $data = [
            'login' => 'test',
            'password' => 'test',
            'password_confirmation' => 'test2',
            'plan' => 'individual',
        ];

        $response = $this->post('/api/auth/signup', $data);
        $json = $response->json();

        $response->assertStatus(422);
        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']);
        $this->assertArrayHasKey('password', $json['errors']);
        $this->assertArrayHasKey('domain', $json['errors']);

        $domain = $this->getPublicDomain();

        // Login too short, password too short
        $data = [
            'login' => '1',
            'domain' => $domain,
            'password' => 'test',
            'password_confirmation' => 'test',
            'plan' => 'indvalid',
        ];

        $response = $this->post('/api/auth/signup', $data);
        $json = $response->json();

        $response->assertStatus(422);
        $this->assertSame('error', $json['status']);
        $this->assertCount(3, $json['errors']);
        $this->assertArrayHasKey('login', $json['errors']);
        $this->assertArrayHasKey('password', $json['errors']);
        $this->assertArrayHasKey('plan', $json['errors']);

        // Missing codes
        $data = [
            'login' => 'login-valid',
            'domain' => $domain,
            'password' => 'testtest',
            'password_confirmation' => 'testtest',
            'plan' => 'individual',
        ];

        $response = $this->post('/api/auth/signup', $data);
        $json = $response->json();

        $response->assertStatus(422);
        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']);
        $this->assertArrayHasKey('code', $json['errors']);
        $this->assertArrayHasKey('short_code', $json['errors']);

        // Data with invalid short_code
        $data = [
            'login' => 'TestLogin',
            'domain' => $domain,
            'password' => 'testtest',
            'password_confirmation' => 'testtest',
            'code' => $result['code'],
            'short_code' => 'XXXX',
        ];

        $response = $this->post('/api/auth/signup', $data);
        $json = $response->json();

        $response->assertStatus(422);
        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertArrayHasKey('short_code', $json['errors']);

        $code = SignupCode::find($result['code']);

        // Data with invalid voucher
        $data = [
            'login' => 'TestLogin',
            'domain' => $domain,
            'password' => 'testtest',
            'password_confirmation' => 'testtest',
            'code' => $result['code'],
            'short_code' => $code->short_code,
            'voucher' => 'XXX',
        ];

        $response = $this->post('/api/auth/signup', $data);
        $json = $response->json();

        $response->assertStatus(422);
        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertArrayHasKey('voucher', $json['errors']);

        // Valid code, invalid login
        $data = [
            'login' => 'żżżżżż',
            'domain' => $domain,
            'password' => 'testtest',
            'password_confirmation' => 'testtest',
            'code' => $result['code'],
            'short_code' => $code->short_code,
        ];

        $response = $this->post('/api/auth/signup', $data);
        $json = $response->json();

        $response->assertStatus(422);
        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertArrayHasKey('login', $json['errors']);
    }

    /**
     * Test last signup step with valid input (user creation)
     *
     * @depends testSignupVerifyValidInput
     */
    public function testSignupValidInput(array $result): void
    {
        $queue = Queue::fake();

        $domain = $this->getPublicDomain();
        $identity = \strtolower('SignupLogin@') . $domain;
        $code = SignupCode::find($result['code']);
        $code->ip_address = '10.1.1.2';
        $code->verify_ip_address = '10.1.1.3';
        $code->save();
        $data = [
            'login' => 'SignupLogin',
            'domain' => $domain,
            'password' => 'testtest',
            'password_confirmation' => 'testtest',
            'code' => $code->code,
            'short_code' => $code->short_code,
            'voucher' => 'TEST',
        ];

        $response = $this->post('/api/auth/signup', $data, ['REMOTE_ADDR' => '10.1.1.4']);
        $json = $response->json();

        $response->assertStatus(200);
        $this->assertSame('success', $json['status']);
        $this->assertSame('bearer', $json['token_type']);
        $this->assertTrue(!empty($json['expires_in']) && is_int($json['expires_in']) && $json['expires_in'] > 0);
        $this->assertNotEmpty($json['access_token']);
        $this->assertSame($identity, $json['user']['email']);

        Queue::assertPushed(CreateJob::class, 1);

        Queue::assertPushed(
            CreateJob::class,
            static function ($job) use ($data) {
                $userEmail = TestCase::getObjectProperty($job, 'userEmail');
                return $userEmail === \strtolower($data['login'] . '@' . $data['domain']);
            }
        );

        $code->refresh();

        // Check if the user has been created
        $user = User::where('email', $identity)->firstOrFail();
        $this->assertTrue($user->isRestricted());
        $this->assertTrue($user->isNew());

        // Check if the code has been updated and soft-deleted
        $this->assertTrue($code->trashed());
        $this->assertSame('10.1.1.2', $code->ip_address);
        $this->assertSame('10.1.1.3', $code->verify_ip_address);
        $this->assertSame('10.1.1.4', $code->submit_ip_address);
        $this->assertSame($user->id, $code->user_id);

        // Check user settings
        $this->assertSame($result['first_name'], $user->getSetting('first_name'));
        $this->assertSame($result['last_name'], $user->getSetting('last_name'));
        $this->assertSame($result['email'], $user->getSetting('external_email'));

        // Discount
        $discount = Discount::where('code', 'TEST')->first();
        $this->assertSame($discount->id, $user->wallets()->first()->discount_id);

        // Test signup with 100% discount
        $code->deleted_at = null;
        $code->user_id = null;
        $code->voucher = 'FREE';
        $code->timestamps = false;
        $code->save();
        $user->forceDeleteQuietly();
        $data['voucher'] = 'FREE';

        // FIXME: For some reason this request becomes http://localhost and returns 405 for that reason
        $this->useRegularUrl(); // this fixes the issue
        $this->post('/api/auth/signup', $data)->assertStatus(200);

        $user = User::where('email', $identity)->firstOrFail();
        $this->assertFalse($user->isRestricted());
        $this->assertTrue($user->isNew());

        $discount = Discount::where('code', 'FREE')->first();
        $this->assertSame($discount->id, $user->wallets()->first()->discount_id);
    }

    /**
     * Test signup for a group (custom domain) account
     */
    public function testSignupGroupAccount(): void
    {
        Queue::fake();

        // Initial signup request
        $user_data = $data = [
            'email' => 'testuser@external.com',
            'first_name' => 'Signup',
            'last_name' => 'User',
            'plan' => 'group',
        ];

        $response = $this->withoutMiddleware()->post('/api/auth/signup/init', $data);
        $json = $response->json();

        $response->assertStatus(200);
        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame('email', $json['mode']);
        $this->assertTrue($json['is_domain']);
        $this->assertNotEmpty($json['code']);
        $this->assertSame(Domain::getPublicDomains(), $json['domains']);

        // Assert the email sending job was pushed once
        Queue::assertPushed(SignupVerificationJob::class, 1);

        // Assert the job has proper data assigned
        Queue::assertPushed(SignupVerificationJob::class, static function ($job) use ($data, $json) {
            $code = TestCase::getObjectProperty($job, 'code');

            return $code->code === $json['code']
                && $code->plan === $data['plan']
                && $code->email === $data['email']
                && $code->first_name === $data['first_name']
                && $code->last_name === $data['last_name'];
        });

        // Verify the code
        $code = SignupCode::find($json['code']);
        $data = [
            'code' => $code->code,
            'short_code' => $code->short_code,
        ];

        $response = $this->post('/api/auth/signup/verify', $data);
        $result = $response->json();

        $response->assertStatus(200);
        $this->assertCount(6, $result);
        $this->assertSame('success', $result['status']);
        $this->assertSame($user_data['email'], $result['email']);
        $this->assertSame($user_data['first_name'], $result['first_name']);
        $this->assertSame($user_data['last_name'], $result['last_name']);
        $this->assertNull($result['voucher']);
        $this->assertTrue($result['is_domain']);

        // Final signup request
        $login = 'admin';
        $domain = 'external.com';
        $data = [
            'login' => $login,
            'domain' => $domain,
            'password' => 'testtest',
            'password_confirmation' => 'testtest',
            'code' => $code->code,
            'short_code' => $code->short_code,
        ];

        $response = $this->post('/api/auth/signup', $data);
        $result = $response->json();

        $response->assertStatus(200);
        $this->assertSame('success', $result['status']);
        $this->assertSame('bearer', $result['token_type']);
        $this->assertTrue(!empty($result['expires_in']) && is_int($result['expires_in']) && $result['expires_in'] > 0);
        $this->assertNotEmpty($result['access_token']);
        $this->assertSame("{$login}@{$domain}", $result['user']['email']);

        Queue::assertPushed(\App\Jobs\Domain\CreateJob::class, 1);

        Queue::assertPushed(
            \App\Jobs\Domain\CreateJob::class,
            static function ($job) use ($domain) {
                $domainNamespace = TestCase::getObjectProperty($job, 'domainNamespace');

                return $domainNamespace === $domain;
            }
        );

        Queue::assertPushed(CreateJob::class, 1);

        Queue::assertPushed(
            CreateJob::class,
            static function ($job) use ($data) {
                $userEmail = TestCase::getObjectProperty($job, 'userEmail');

                return $userEmail === $data['login'] . '@' . $data['domain'];
            }
        );

        // Check if the code has been removed
        $code->refresh();
        $this->assertTrue($code->trashed());

        // Check if the user has been created
        $user = User::where('email', $login . '@' . $domain)->first();

        $this->assertNotEmpty($user);
        $this->assertTrue($user->isRestricted());

        // Check user settings
        $this->assertSame($user_data['email'], $user->getSetting('external_email'));
        $this->assertSame($user_data['first_name'], $user->getSetting('first_name'));
        $this->assertSame($user_data['last_name'], $user->getSetting('last_name'));

        // TODO: Check domain record

        // TODO: Check SKUs/Plan

        // TODO: Check if the access token works
    }

    /**
     * Test signup with mode=mandate
     *
     * @group mollie
     */
    public function testSignupMandateMode(): void
    {
        if (!\config('services.mollie.key')) {
            $this->markTestSkipped('No MOLLIE_KEY');
        }

        Queue::fake();

        \config(['services.payment_provider' => 'mollie']);

        $plan = Plan::create([
            'title' => 'test',
            'name' => 'Test Account',
            'description' => 'Test',
            'free_months' => 1,
            'discount_qty' => 0,
            'discount_rate' => 0,
            'mode' => Plan::MODE_MANDATE,
        ]);

        $packages = [
            Package::where(['title' => 'kolab', 'tenant_id' => \config('app.tenant_id')])->first(),
        ];

        $plan->packages()->saveMany($packages);

        $post = [
            'plan' => 'abc',
            'login' => 'test-inv',
            'domain' => 'kolabnow.com',
            'password' => 'testtest',
            'password_confirmation' => 'testtest',
        ];

        // Test invalid plan identifier
        $response = $this->post('/api/auth/signup', $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertSame(["The plan field is required."], $json['errors']['plan']);

        // Test valid input
        $post['plan'] = $plan->title;
        $response = $this->post('/api/auth/signup', $post);
        $json = $response->json();

        $response->assertStatus(200);
        $this->assertSame('success', $json['status']);
        $this->assertNotEmpty($json['access_token']);
        $this->assertSame('test-inv@kolabnow.com', $json['user']['email']);
        $this->assertTrue($json['user']['isLocked']);
        $user = User::where('email', 'test-inv@kolabnow.com')->first();
        $this->assertNotEmpty($user);
        $this->assertSame($plan->id, $user->getSetting('plan_id'));
        $this->assertSame('You are choosing a monthly subscription.', $json['checkout']['title']);
        $this->assertTrue(!empty($json['checkout']['id']));
    }

    /**
     * Test signup via invitation
     */
    public function testSignupInvitation(): void
    {
        Queue::fake();

        $invitation = SI::create(['email' => 'email1@ext.com']);

        $post = [
            'invitation' => 'abc',
            'first_name' => 'Signup',
            'last_name' => 'User',
            'login' => 'test-inv',
            'domain' => 'kolabnow.com',
            'password' => 'testtest',
            'password_confirmation' => 'testtest',
            'plan' => 'individual',
        ];

        // Test invalid invitation identifier
        $response = $this->post('/api/auth/signup', $post);
        $response->assertStatus(404);

        // Test invalid plan
        $post['invitation'] = $invitation->id;
        $post['plan'] = 'invalid';
        $response = $this->post('/api/auth/signup', $post);
        $response->assertStatus(422);

        // Test valid input
        $post['plan'] = 'individual';
        $response = $this->post('/api/auth/signup', $post);
        $result = $response->json();

        $response->assertStatus(200);
        $this->assertSame('success', $result['status']);
        $this->assertSame('bearer', $result['token_type']);
        $this->assertTrue(!empty($result['expires_in']) && is_int($result['expires_in']) && $result['expires_in'] > 0);
        $this->assertNotEmpty($result['access_token']);
        $this->assertSame('test-inv@kolabnow.com', $result['user']['email']);

        // Check if the user has been created
        $user = User::where('email', 'test-inv@kolabnow.com')->first();

        $this->assertNotEmpty($user);

        // Check user settings
        $this->assertSame($invitation->email, $user->getSetting('external_email'));
        $this->assertSame($post['first_name'], $user->getSetting('first_name'));
        $this->assertSame($post['last_name'], $user->getSetting('last_name'));

        $invitation->refresh();

        $this->assertSame($user->id, $invitation->user_id);
        $this->assertTrue($invitation->isCompleted());

        // TODO: Test POST params validation
    }

    /**
     * Test signup via token
     */
    public function testSignupToken(): void
    {
        Queue::fake();

        $plan = Plan::create([
            'title' => 'test',
            'name' => 'Test Account',
            'description' => 'Test',
            'free_months' => 1,
            'discount_qty' => 0,
            'discount_rate' => 0,
            'mode' => Plan::MODE_TOKEN,
        ]);

        $post = [
            'plan' => $plan->title,
            'token' => 'abc',
            'login' => 'test-inv',
            'domain' => 'kolabnow.com',
            'password' => 'testtest',
            'password_confirmation' => 'testtest',
        ];

        // Test invalid token
        $response = $this->post('/api/auth/signup', $post);
        $response->assertStatus(422);
        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame(['token' => ["The signup token is invalid."]], $json['errors']);

        // Test valid token
        $token = SignupToken::create(['id' => 'abc', 'plans' => [$plan->id]]);
        $post['plan'] = $plan->title;
        $response = $this->post('/api/auth/signup', $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame('test-inv@kolabnow.com', $json['user']['email']);

        // Check if the user has been created
        $user = User::where('email', 'test-inv@kolabnow.com')->first();
        $this->assertNotEmpty($user);
        $this->assertSame($plan->id, $user->getSetting('plan_id'));
        $this->assertSame($token->id, $user->getSetting('signup_token'));
        $this->assertNull($user->getSetting('external_email'));

        // Token's counter bumped up
        $this->assertSame(1, $token->fresh()->counter);
    }

    /**
     * Test signup vith a referral program
     */
    public function testSignupWithReferralCode(): void
    {
        Queue::fake();

        $referrer = $this->getTestUser('john@kolab.org');
        $discount = Discount::where('code', 'TEST')->first();
        $program = ReferralProgram::create([
            'name' => "Test Referral",
            'description' => "Test Referral Description",
            'active' => true,
            'discount_id' => $discount->id,
        ]);
        $referral_code = $program->codes()->create(['user_id' => $referrer->id]);

        $post = [
            'referral' => 'abc',
            'first_name' => 'Signup',
            'last_name' => 'User',
            'email' => 'test@domain.ltd',
            'login' => 'test-inv',
            'domain' => 'kolabnow.com',
            'password' => 'testtest',
            'password_confirmation' => 'testtest',
        ];

        // Test invalid referral code
        $response = $this->post('/api/auth/signup/init', $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']);
        $this->assertSame(['The referral program code is invalid.'], $json['errors']['referral']);
        $this->assertSame(['The plan field is required.'], $json['errors']['plan']);

        // Test valid code
        $post['referral'] = $referral_code->code;
        $post['plan'] = 'individual';
        $response = $this->post('/api/auth/signup/init', $post);

        $json = $response->json();

        $signup_code = SignupCode::find($json['code']);
        $post['code'] = $signup_code->code;
        $post['short_code'] = $signup_code->short_code;

        // Test final signup request
        $response = $this->post('/api/auth/signup', $post);
        $json = $response->json();

        $response->assertStatus(200);
        $this->assertSame('success', $json['status']);
        $this->assertNotEmpty($json['access_token']);

        // Check the reference to the code and discount
        $user = User::where('email', $json['user']['email'])->first();
        $this->assertSame(1, $referral_code->referrals()->where('user_id', $user->id)->count());
        $this->assertSame($discount->id, $user->wallets()->first()->discount_id);
    }

    /**
     * Test signup validation (POST /signup/validate)
     */
    public function testSignupValidate(): void
    {
        Queue::fake();

        $plan = Plan::create([
            'title' => 'test',
            'name' => 'Test Account',
            'description' => 'Test',
            'free_months' => 1,
            'months' => 12,
            'discount_qty' => 0,
            'discount_rate' => 0,
            'mode' => Plan::MODE_MANDATE,
        ]);

        $packages = [
            Package::where(['title' => 'kolab', 'tenant_id' => \config('app.tenant_id')])->first(),
        ];

        $plan->packages()->saveMany($packages);

        $post = [
            'login' => 'i',
            'password' => 'testtest',
            'password_confirmation' => 'testtest1',
            'voucher' => str_repeat('a', 33),
        ];

        // Test basic input validation
        $response = $this->post('/api/auth/signup/validate', $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(5, $json['errors']);
        $this->assertSame(["The login must be at least 2 characters."], $json['errors']['login']);
        $this->assertSame(["The password confirmation does not match."], $json['errors']['password']);
        $this->assertSame(["The domain field is required."], $json['errors']['domain']);
        $this->assertSame(["The voucher may not be greater than 32 characters."], $json['errors']['voucher']);
        $this->assertSame(["The plan field is required."], $json['errors']['plan']);

        // Test with mode=mandate plan, but invalid voucher code
        $post = [
            'login' => 'test-inv',
            'domain' => 'kolabnow.com',
            'password' => 'testtest',
            'password_confirmation' => 'testtest',
            'plan' => $plan->title,
            'voucher' => 'non-existing',
        ];

        $response = $this->post('/api/auth/signup/validate', $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertSame("The voucher code is invalid or expired.", $json['errors']['voucher']);

        // Prepare VAT rate and network entries, so we can test the VAT related output
        VatRate::create([
            'country' => 'CH',
            'rate' => 7.7,
            'start' => now()->copy()->subDay(),
        ]);

        IP4Net::create([
            'net_number' => '128.0.0.0',
            'net_broadcast' => '128.255.255.255',
            'net_mask' => 8,
            'country' => 'CH',
            'rir_name' => 'test',
            'serial' => 1,
        ]);

        // Test with mode=mandate plan, and valid voucher code
        $post['voucher'] = 'TEST';
        $headers = ['X-Client-IP' => '128.0.0.2'];
        $response = $this->withHeaders($headers)->post('/api/auth/signup/validate', $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertTrue(empty($json['id']));
        $this->assertTrue(!empty($json['content']));
        $this->assertSame('You are choosing a yearly subscription.', $json['title']);
        $this->assertSame(
            '<table>'
            . '<tr class="subscription"><td>Yearly subscription</td><td class="money">118,80 CHF</td></tr>'
            . '<tr class="discount"><td>Discount: TEST</td><td class="money">-11,88 CHF</td></tr>'
            . '<tr class="sep"><td colspan="2"></td></tr>'
            . '<tr class="total"><td>Total</td><td class="money">106,92 CHF</td></tr>'
            . '<tr class="vat-summary"><td colspan="2">*Incl. VAT 8,23 CHF (7,7 % of 98,69 CHF)</td></tr>'
            . '</table>',
            $json['summary']
        );

        // TODO: Test other plan modes
    }

    /**
     * Signup login/domain validation.
     *
     * Note: Technically these include unit tests, but let's keep it here for now.
     * FIXME: Shall we do a http request for each case?
     */
    public function testValidateLogin(): void
    {
        $domain = $this->getPublicDomain();

        $cases = [
            // Individual account
            ['', $domain, false, ['login' => 'The login field is required.']],
            ['test123456', 'localhost', false, ['domain' => 'The specified domain is invalid.']],
            ['test123456', 'unknown-domain.org', false, ['domain' => 'The specified domain is invalid.']],
            ['test.test', $domain, false, null],
            ['test_test', $domain, false, null],
            ['test-test', $domain, false, null],
            ['test-test', 'kolab.org', false, ['domain' => 'The specified domain is not available.']],
            ['admin', $domain, false, ['login' => 'The specified login is not available.']],
            ['administrator', $domain, false, ['login' => 'The specified login is not available.']],
            ['sales', $domain, false, ['login' => 'The specified login is not available.']],
            ['root', $domain, false, ['login' => 'The specified login is not available.']],
            ['anonymous', $domain, false, ['login' => 'The specified login is not available.']],
            ['anonymous', 'kolabsys.com', false, ['login' => 'The specified login is not available.']],

            // Domain account
            ['admin', 'kolabsys.com', true, null],
            ['testsystemdomain', $domain, true, null],
            ['testnonsystemdomain', 'invalid', true, ['domain' => 'The specified domain is invalid.']],
            ['testnonsystemdomain', '.com', true, ['domain' => 'The specified domain is invalid.']],
        ];

        foreach ($cases as $case) {
            [$login, $domain, $external, $expected_result] = $case;
            $result = $this->invokeMethod(new SignupController(), 'validateLogin', [$login, $domain, $external]);

            $this->assertSame($expected_result, $result);
        }
    }

    /**
     * Signup login/domain validation, more cases
     */
    public function testValidateLoginMore(): void
    {
        Queue::fake();

        // Test registering for an email of an existing group
        $login = 'group-test';
        $domain = 'kolabnow.com';
        $group = $this->getTestGroup("{$login}@{$domain}");
        $external = false;

        $result = $this->invokeMethod(new SignupController(), 'validateLogin', [$login, $domain, $external]);

        $this->assertSame(['login' => 'The specified login is not available.'], $result);

        // Test registering for an email of an existing, but soft-deleted group
        $group->delete();

        $result = $this->invokeMethod(new SignupController(), 'validateLogin', [$login, $domain, $external]);

        $this->assertSame(['login' => 'The specified login is not available.'], $result);

        // Test registering for an email of an existing user
        $domain = $this->getPublicDomain();
        $login = 'signuplogin';
        $user = $this->getTestUser("{$login}@{$domain}");
        $external = false;

        $result = $this->invokeMethod(new SignupController(), 'validateLogin', [$login, $domain, $external]);

        $this->assertSame(['login' => 'The specified login is not available.'], $result);

        // Test registering for an email of an existing, but soft-deleted user
        $user->delete();

        $result = $this->invokeMethod(new SignupController(), 'validateLogin', [$login, $domain, $external]);

        $this->assertSame(['login' => 'The specified login is not available.'], $result);

        // Test registering for a domain that exists
        $external = true;
        $domain = $this->getTestDomain(
            'external.com',
            ['status' => Domain::STATUS_NEW, 'type' => Domain::TYPE_EXTERNAL]
        );

        $result = $this->invokeMethod(new SignupController(), 'validateLogin', [$login, $domain->namespace, $external]);

        $this->assertSame(['domain' => 'The specified domain is not available.'], $result);

        // Test registering for a domain that exists but is soft-deleted
        $domain->delete();

        $result = $this->invokeMethod(new SignupController(), 'validateLogin', [$login, $domain->namespace, $external]);

        $this->assertSame(['domain' => 'The specified domain is not available.'], $result);
    }
}
