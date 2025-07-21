<?php

namespace Tests\Feature\Policy;

use App\Policy\Mailfilter;
use App\Policy\Mailfilter\Modules\ExternalSenderModule;
use App\Policy\Mailfilter\Modules\ItipModule;
use App\Policy\Mailfilter\Modules\TestModule;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MailfilterTest extends TestCase
{
    private $keys = [
        'externalsender_config',
        'externalsender_policy',
        'externalsender_policy_domains',
        'itip_config',
        'itip_policy',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $john = $this->getTestUser('john@kolab.org');
        $john->settings()->whereIn('key', $this->keys)->delete();
        $jack = $this->getTestUser('jack@kolab.org');
        $jack->settings()->whereIn('key', $this->keys)->delete();
    }

    protected function tearDown(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $john->settings()->whereIn('key', $this->keys)->delete();
        $jack = $this->getTestUser('jack@kolab.org');
        $jack->settings()->whereIn('key', $this->keys)->delete();

        parent::tearDown();
    }

    /**
     * Test mail filter basic functionality
     */
    public function testHandle()
    {
        $mail = file_get_contents(self::BASE_DIR . '/data/mail/1.eml');
        $mail = str_replace("\n", "\r\n", $mail);

        $john = $this->getTestUser('john@kolab.org');

        // Note: We use the HTTP controller here for easier use of Laravel request/response assertions
        $this->useServicesUrl();

        // Test unknown recipient
        $url = '/api/webhooks/policy/mail/filter?recipient=unknown@domain.tld&sender=jack@kolab.org';
        $this->call('POST', $url, [], [], [], [], $mail)
            ->assertStatus(200)
            ->assertHeader(Mailfilter::HEADER, Mailfilter::HEADER_ACTION_ACCEPT_EMPTY)
            ->assertContent('');

        // No modules enabled, no changes to the mail content
        $url = '/api/webhooks/policy/mail/filter?recipient=john@kolab.org&sender=jack@kolab.org';
        $this->call('POST', $url, [], [], [], [], $mail)
            ->assertStatus(200)
            ->assertHeader(Mailfilter::HEADER, Mailfilter::HEADER_ACTION_ACCEPT_EMPTY)
            ->assertContent('');

        // Test returning (modified) mail content
        $john->setConfig(['externalsender_policy' => true]);
        $url = '/api/webhooks/policy/mail/filter?recipient=john@kolab.org&sender=jack@external.tld';
        $content = $this->call('POST', $url, [], [], [], [], $mail)
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'message/rfc822')
            ->assertHeader(Mailfilter::HEADER, Mailfilter::HEADER_ACTION_ACCEPT)
            ->streamedContent();

        $this->assertStringContainsString('Subject: [EXTERNAL] test sync', $content);
        $this->assertStringContainsString('ZWVlYQ==', $content);

        // Test multipart/form-data request
        $file = UploadedFile::fake()->createWithContent('mail.eml', $mail);
        $content = $this->call('POST', $url, ['file' => $file], [], [], [])
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'message/rfc822')
            ->assertHeader(Mailfilter::HEADER, Mailfilter::HEADER_ACTION_ACCEPT)
            ->streamedContent();

        $this->assertStringContainsString('Subject: [EXTERNAL] test sync', $content);
        $this->assertStringContainsString('ZWVlYQ==', $content);

        // Test request with no file attached and no content
        $this->call('POST', $url, [], [], [], [])->assertStatus(500);

        // Test two modules that both modify the mail content
        $mail = str_replace('test sync', 'KOLABv4TestMessage MODIFYSUBJECT', $mail);
        $content = $this->call('POST', $url, [], [], [], [], $mail)
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'message/rfc822')
            ->assertHeader(Mailfilter::HEADER, Mailfilter::HEADER_ACTION_ACCEPT)
            ->streamedContent();

        $this->assertStringContainsString('Subject: [EXTERNAL] KOLABv4TestMessage MODIFYSUBJECT MODIFIED', $content);
        $this->assertStringContainsString('ZWVlYQ==', $content);

        // Test rejecting mail
        $mail = str_replace('KOLABv4TestMessage MODIFYSUBJECT', 'KOLABv4TestMessage REJECT', $mail);
        $this->call('POST', $url, [], [], [], [], $mail)
            ->assertStatus(200)
            ->assertHeader(Mailfilter::HEADER, Mailfilter::HEADER_ACTION_REJECT)
            ->assertContent('');

        // Test discarding mail
        $mail = str_replace('REJECT', 'DISCARD', $mail);
        $this->call('POST', $url, [], [], [], [], $mail)
            ->assertStatus(200)
            ->assertHeader(Mailfilter::HEADER, Mailfilter::HEADER_ACTION_DISCARD)
            ->assertContent('');
    }

    /**
     * Test reading modules configuration/policy
     */
    public function testGetModulesConfig()
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $filter = new Mailfilter();

        $expected_default = [
            TestModule::class => [],
        ];

        // No module configured yet, no policy, no config
        $this->assertSame($expected_default, $this->invokeMethod($filter, 'getModulesConfig', [$john]));
        $this->assertSame($expected_default, $this->invokeMethod($filter, 'getModulesConfig', [$jack]));

        // Enable account policies
        $john->setConfig(['externalsender_policy' => true, 'itip_policy' => true]);
        $expected = [
            TestModule::class => [],
            ItipModule::class => [
                'itip_config' => null,
                'itip_policy' => true,
            ],
            ExternalSenderModule::class => [
                'externalsender_config' => null,
                'externalsender_policy' => true,
                'externalsender_policy_domains' => [],
            ],
        ];

        $this->assertSame($expected, $this->invokeMethod($filter, 'getModulesConfig', [$john]));
        $this->assertSame($expected, $this->invokeMethod($filter, 'getModulesConfig', [$jack]));

        // Enabled account policies, and enabled per-user config
        $jack->setConfig(['externalsender_config' => true, 'itip_config' => true]);

        $result = $this->invokeMethod($filter, 'getModulesConfig', [$jack]);
        $this->assertTrue($result[ExternalSenderModule::class]['externalsender_config']);
        $this->assertTrue($result[ItipModule::class]['itip_config']);

        // Enabled account policies, and disabled per-user config
        $jack->setConfig(['externalsender_config' => false, 'itip_config' => false]);

        $this->assertSame($expected_default, $this->invokeMethod($filter, 'getModulesConfig', [$jack]));

        // Disabled account policies, and disabled per-user config
        $john->setConfig(['externalsender_policy' => false, 'itip_policy' => false]);

        $this->assertSame($expected_default, $this->invokeMethod($filter, 'getModulesConfig', [$john]));
        $this->assertSame($expected_default, $this->invokeMethod($filter, 'getModulesConfig', [$jack]));

        // Disabled account policies, and enabled per-user config
        $jack->setConfig(['externalsender_config' => true, 'itip_config' => true]);

        $result = $this->invokeMethod($filter, 'getModulesConfig', [$jack]);
        $this->assertTrue($result[ExternalSenderModule::class]['externalsender_config']); // @phpstan-ignore-line
        $this->assertTrue($result[ItipModule::class]['itip_config']); // @phpstan-ignore-line

        // As the last one, but for account owner
        $john->setConfig(['externalsender_config' => true, 'itip_config' => true]);

        $result = $this->invokeMethod($filter, 'getModulesConfig', [$john]);
        $this->assertTrue($result[ExternalSenderModule::class]['externalsender_config']);
        $this->assertTrue($result[ItipModule::class]['itip_config']);
    }
}
