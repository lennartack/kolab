<?php

namespace Tests\Feature\Policy\Mailfilter\Modules\ItipModule;

use App\DataMigrator\Account;
use App\Policy\Mailfilter\Modules\ItipModule;
use App\Policy\Mailfilter\Notifications\ItipNotification;
use App\Policy\Mailfilter\Result;
use Illuminate\Support\Facades\Notification;
use Tests\BackendsTrait;
use Tests\TestCase;
use Tests\Unit\Policy\Mailfilter\MailParserTest;

/**
 * @todo Mock the DAV server to make these tests faster
 */
class RequestHandlerTest extends TestCase
{
    use BackendsTrait;

    /**
     * Test REQUEST method
     *
     * @group dav
     */
    public function testItipRequest(): void
    {
        Notification::fake();

        $uri = preg_replace('|^http|', 'dav', \config('services.dav.uri'));
        $account = new Account(preg_replace('|://|', '://john%40kolab.org:simple123@', $uri));

        $this->davEmptyFolder($account, 'Calendar', 'event');

        // Jack invites John (and Ned) to a new meeting
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_request.eml', 'john@kolab.org', 'jack@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertSame('5463F1DDF6DA264A3FC70E7924B729A5-D9F1889254B163F5', $list[0]->uid);
        $this->assertCount(2, $list[0]->attendees);
        $this->assertSame('john@kolab.org', $list[0]->attendees[0]['email']);
        $this->assertSame('NEEDS-ACTION', $list[0]->attendees[0]['partstat']);
        $this->assertSame('ned@kolab.org', $list[0]->attendees[1]['email']);
        $this->assertSame('NEEDS-ACTION', $list[0]->attendees[1]['partstat']);
        $this->assertSame('jack@kolab.org', $list[0]->organizer['email']);

        Notification::assertNothingSent();

        // Test REQUEST to an existing event
        $replaces = [
            'CN=Ned;PARTSTAT=NEEDS-ACTION' => 'CN=Ned;PARTSTAT=ACCEPTED',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_request.eml', 'john@kolab.org', 'jack@kolab.org', $replaces);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertSame('5463F1DDF6DA264A3FC70E7924B729A5-D9F1889254B163F5', $list[0]->uid);
        $this->assertSame('Test Meeting 1', $list[0]->summary);
        $this->assertCount(2, $list[0]->attendees);
        $this->assertSame('john@kolab.org', $list[0]->attendees[0]['email']);
        $this->assertSame('NEEDS-ACTION', $list[0]->attendees[0]['partstat']);
        $this->assertSame('ned@kolab.org', $list[0]->attendees[1]['email']);
        $this->assertSame('ACCEPTED', $list[0]->attendees[1]['partstat']);
        $this->assertSame('jack@kolab.org', $list[0]->organizer['email']);

        Notification::assertNothingSent();

        // Jack sends a REQUEST with old SEQUENCE (master event, not exception)
        $this->davEmptyFolder($account, 'Calendar', 'event');
        $this->davAppend($account, 'Calendar', ['mailfilter/event5.ics'], 'event');

        $parser = MailParserTest::getParserForFile('mailfilter/itip3_request_rrule_update.eml', 'john@kolab.org', 'jack@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertCount(2, $list[0]->exceptions);
        $this->assertSame(1, (int) $list[0]->sequence);

        // Jack sends a REQUEST with updated SEQUENCE (master event, not exception)
        $replace = [
            'SEQUENCE:0' => 'SEQUENCE:2',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip3_request_rrule_update.eml', 'john@kolab.org', 'jack@kolab.org', $replace);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertCount(0, $list[0]->exceptions);
        $this->assertSame(2, (int) $list[0]->sequence);

        Notification::assertNothingSent();
    }

    /**
     * Test REQUEST method against checkRecipient()
     *
     * @group dav
     */
    public function testItipRequestCheckRecipient(): void
    {
        Notification::fake();

        $uri = preg_replace('|^http|', 'dav', \config('services.dav.uri'));
        $account = new Account(preg_replace('|://|', '://john%40kolab.org:simple123@', $uri));

        $this->davEmptyFolder($account, 'Calendar', 'event');

        // Test a REQUEST where none of the ATTENDEEs is the recipient
        $replace = [
            'john@kolab.org' => 'johnathan@kolab.org',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_request.eml', 'john@kolab.org', 'jack@kolab.org', $replace);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(0, $list = $this->davList($account, 'Calendar', 'event'));

        // Test a REQUEST where ATTENDEE is the recipient
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_request.eml', 'john@kolab.org', 'jack@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertCount(2, $list[0]->attendees);
        $this->assertSame('john@kolab.org', $list[0]->attendees[0]['email']);
        $this->assertSame('ned@kolab.org', $list[0]->attendees[1]['email']);

        // Test a REQUEST where ATTENDEE is the recipient's alias
        $replace = [
            'john@kolab.org' => 'john.doe@kolab.org',
            'SEQUENCE:0' => 'SEQUENCE:1',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_request.eml', 'john@kolab.org', 'jack@kolab.org', $replace);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertCount(2, $list[0]->attendees);
        $this->assertSame('john.doe@kolab.org', $list[0]->attendees[0]['email']);
        $this->assertSame('ned@kolab.org', $list[0]->attendees[1]['email']);

        Notification::assertNothingSent();
    }

    /**
     * Test notifications on a REQUEST
     *
     * @group dav
     */
    public function testItipRequestNotifications(): void
    {
        Notification::fake();

        $uri = preg_replace('|^http|', 'dav', \config('services.dav.uri'));
        $account = new Account(preg_replace('|://|', '://john%40kolab.org:simple123@', $uri));

        $this->davEmptyFolder($account, 'Calendar', 'event');
        $this->davAppend($account, 'Calendar', ['mailfilter/event5.ics'], 'event');

        // Jack sends a REQUEST with same SEQUENCE, a notification is expected
        $replace = [
            'SEQUENCE:0' => 'SEQUENCE:1',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip3_request_rrule_update.eml', 'john@kolab.org', 'jack@kolab.org', $replace);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertSame(Result::STATUS_DISCARD, $result->getStatus());
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertCount(0, $list[0]->exceptions);

        Notification::assertCount(1);
        Notification::assertSentTo(
            $user = $this->getTestUser('john@kolab.org'),
            static function (ItipNotification $notification, array $channels, object $notifiable) use ($user) {
                return $notifiable->id == $user->id
                    && $notification->params->mode == 'request'
                    && $notification->params->senderEmail == 'jack@kolab.org'
                    && $notification->params->senderName == 'Jack'
                    && $notification->params->comment == 'Test comment'
                    && $notification->params->start == '2024-07-10 10:30'
                    && $notification->params->summary == 'Test Meeting'
                    && empty($notification->params->recurrenceId);
            }
        );

        Notification::fake();

        // Jack sends a REQUEST with updated SEQUENCE, no notification is expected
        $replace = [
            'SEQUENCE:0' => 'SEQUENCE:2',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip3_request_rrule_update.eml', 'john@kolab.org', 'jack@kolab.org', $replace);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertSame(2, (int) $list[0]->sequence);

        Notification::assertNothingSent();

        // Jack sends a REQUEST with same SEQUENCE, but reset John's PARTSTAT, no notification is expected
        $replace = [
            'SEQUENCE:0' => 'SEQUENCE:2',
            'CN=John;PARTSTAT=ACCEPTED' => 'CN=John;PARTSTAT=NEEDS-ACTION',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip3_request_rrule_update.eml', 'john@kolab.org', 'jack@kolab.org', $replace);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertCount(2, $list[0]->attendees);
        $this->assertSame('john@kolab.org', $list[0]->attendees[0]['email']);
        $this->assertSame('NEEDS-ACTION', $list[0]->attendees[0]['partstat']);

        Notification::assertNothingSent();

        $this->davEmptyFolder($account, 'Calendar', 'event');
        $this->davAppend($account, 'Calendar', ['mailfilter/event4.ics'], 'event');

        // Jack sends a REQUEST to existing occurrence (SEQUENCE bump), no notification expected
        $parser = MailParserTest::getParserForFile('mailfilter/itip2_request.eml', 'john@kolab.org', 'jack@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertCount(1, $list[0]->exceptions);
        $this->assertCount(2, $attendees = $list[0]->exceptions[0]->attendees);
        $this->assertSame('john@kolab.org', $attendees[0]['email']);
        $this->assertSame('NEEDS-ACTION', $attendees[0]['partstat']);

        Notification::assertNothingSent();

        // Jack sends a REQUEST to existing occurrence (PARTSTAT update), a notification is expected
        $replace = [
            'CN=John;PARTSTAT=NEEDS-ACTION' => 'CN=John;PARTSTAT=ACCEPTED',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip2_request.eml', 'john@kolab.org', 'jack@kolab.org', $replace);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertSame(Result::STATUS_DISCARD, $result->getStatus());
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertCount(1, $list[0]->exceptions);
        $this->assertCount(2, $attendees = $list[0]->exceptions[0]->attendees);
        $this->assertSame('john@kolab.org', $attendees[0]['email']);
        $this->assertSame('ACCEPTED', $attendees[0]['partstat']);

        Notification::assertCount(1);
        Notification::assertSentTo(
            $user = $this->getTestUser('john@kolab.org'),
            static function (ItipNotification $notification, array $channels, object $notifiable) use ($user) {
                return $notifiable->id == $user->id
                    && $notification->params->mode == 'request'
                    && $notification->params->senderEmail == 'jack@kolab.org'
                    && $notification->params->senderName == 'Jack'
                    && $notification->params->comment == 'Ex'
                    && $notification->params->start == '2024-07-17 12:30'
                    && $notification->params->summary == 'Test Meeting Ex'
                    && !empty($notification->params->recurrenceId);
            }
        );
    }

    /**
     * Test spoofing protections on REQUEST
     *
     * @group dav
     */
    public function testItipRequestSpoofing(): void
    {
        Notification::fake();

        $uri = preg_replace('|^http|', 'dav', \config('services.dav.uri'));
        $account = new Account(preg_replace('|://|', '://john%40kolab.org:simple123@', $uri));

        $this->davEmptyFolder($account, 'Calendar', 'event');

        // A new meeting, Ned impersonates Jack
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_request.eml', 'john@kolab.org', 'ned@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(0, $this->davList($account, 'Calendar', 'event'));

        Notification::assertNothingSent();

        $this->davAppend($account, 'Calendar', ['mailfilter/event1.ics'], 'event');

        // An existing meeting, Ned impersonates Jack
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_request.eml', 'john@kolab.org', 'ned@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertSame('Test Meeting', $list[0]->summary);
        $this->assertSame('12:43:04', $list[0]->lastModified->getDateTime()->format('H:i:s'));

        Notification::assertNothingSent();

        // An existing meeting, Ned as himself, but the organizer is already Jack
        $replaces = [
            'CN=Jack:mailto:jack@kolab.org' => 'CN=Ned:mailto:ned@kolab.org',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_request.eml', 'john@kolab.org', 'ned@kolab.org', $replaces);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertSame('Test Meeting', $list[0]->summary);
        $this->assertSame('12:43:04', $list[0]->lastModified->getDateTime()->format('H:i:s'));

        Notification::assertNothingSent();
    }

    /**
     * Test REQUEST method with recurrence
     *
     * @group dav
     */
    public function testItipRequestRecurrence(): void
    {
        Notification::fake();

        $uri = preg_replace('|^http|', 'dav', \config('services.dav.uri'));
        $account = new Account(preg_replace('|://|', '://john%40kolab.org:simple123@', $uri));

        $this->davEmptyFolder($account, 'Calendar', 'event');

        // A recurrence exception in the iTip, but the event does not exist yet
        $parser = MailParserTest::getParserForFile('mailfilter/itip2_request.eml', 'john@kolab.org', 'jack@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(0, $this->davList($account, 'Calendar', 'event'));

        $this->davAppend($account, 'Calendar', ['mailfilter/event3.ics'], 'event');

        // Jack invites John (and Ned) to a new meeting occurrence, the event
        // is already in John's calendar and has no recurrence exceptions yet
        $parser = MailParserTest::getParserForFile('mailfilter/itip2_request.eml', 'john@kolab.org', 'jack@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertSame('5463F1DDF6DA264A3FC70E7924B729A5-222222', $list[0]->uid);
        $this->assertCount(2, $attendees = $list[0]->attendees);
        $this->assertSame('john@kolab.org', $attendees[0]['email']);
        $this->assertSame('ACCEPTED', $attendees[0]['partstat']);
        $this->assertSame('ned@kolab.org', $attendees[1]['email']);
        $this->assertSame('TENTATIVE', $attendees[1]['partstat']);
        $this->assertSame('jack@kolab.org', $list[0]->organizer['email']);
        $this->assertCount(1, $list[0]->exceptions);
        $this->assertCount(2, $attendees = $list[0]->exceptions[0]->attendees);
        $this->assertSame('john@kolab.org', $attendees[0]['email']);
        $this->assertSame('NEEDS-ACTION', $attendees[0]['partstat']);
        $this->assertSame('ned@kolab.org', $attendees[1]['email']);
        $this->assertSame('NEEDS-ACTION', $attendees[1]['partstat']);

        // Test updating an existing occurence using old SEQUENCE - expect no changes to the event
        $replaces = [
            'CN=Ned;PARTSTAT=NEEDS-ACTION' => 'CN=Ned;PARTSTAT=ACCEPTED',
            'SEQUENCE:1' => 'SEQUENCE:0',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip2_request.eml', 'john@kolab.org', 'jack@kolab.org', $replaces);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertCount(1, $list[0]->exceptions);
        $this->assertCount(2, $attendees = $list[0]->exceptions[0]->attendees);
        $this->assertSame('ned@kolab.org', $attendees[1]['email']);
        $this->assertSame('NEEDS-ACTION', $attendees[1]['partstat']);

        // Test updating an existing occurence using same SEQUENCE as in the existing exception
        $replaces = [
            'CN=Ned;PARTSTAT=NEEDS-ACTION' => 'CN=Ned;PARTSTAT=ACCEPTED',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip2_request.eml', 'john@kolab.org', 'jack@kolab.org', $replaces);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertSame('5463F1DDF6DA264A3FC70E7924B729A5-222222', $list[0]->uid);
        $this->assertCount(2, $attendees = $list[0]->attendees);
        $this->assertSame('john@kolab.org', $attendees[0]['email']);
        $this->assertSame('ACCEPTED', $attendees[0]['partstat']);
        $this->assertSame('ned@kolab.org', $attendees[1]['email']);
        $this->assertSame('TENTATIVE', $attendees[1]['partstat']);
        $this->assertSame('jack@kolab.org', $list[0]->organizer['email']);
        $this->assertCount(1, $list[0]->exceptions);
        $this->assertCount(2, $attendees = $list[0]->exceptions[0]->attendees);
        $this->assertSame('john@kolab.org', $attendees[0]['email']);
        $this->assertSame('NEEDS-ACTION', $attendees[0]['partstat']);
        $this->assertSame('ned@kolab.org', $attendees[1]['email']);
        $this->assertSame('ACCEPTED', $attendees[1]['partstat']);

        Notification::assertNothingSent();
    }

    /**
     * Test spoofing protection on REQUEST with recurrence
     *
     * @group dav
     */
    public function testItipRequestRecurrenceSpoofing(): void
    {
        Notification::fake();

        $uri = preg_replace('|^http|', 'dav', \config('services.dav.uri'));
        $account = new Account(preg_replace('|://|', '://john%40kolab.org:simple123@', $uri));

        $this->davEmptyFolder($account, 'Calendar', 'event');

        // A recurrence exception in the iTip, but the event does not exist yet, Ned impersonates Jack
        $parser = MailParserTest::getParserForFile('mailfilter/itip2_request.eml', 'john@kolab.org', 'ned@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(0, $this->davList($account, 'Calendar', 'event'));

        $this->davAppend($account, 'Calendar', ['mailfilter/event3.ics'], 'event');

        // The event is already in John's calendar, but has no recurrence exceptions yet, Ned impersonates Jack
        $parser = MailParserTest::getParserForFile('mailfilter/itip2_request.eml', 'john@kolab.org', 'ned@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertCount(0, $list[0]->exceptions);

        // The event is already in John's calendar, but has no recurrence exceptions yet, Ned impersonates Jack
        $replaces = [
            'CN=Jack:mailto:jack@kolab.org' => 'CN=Ned:mailto:ned@kolab.org',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip2_request.eml', 'john@kolab.org', 'ned@kolab.org', $replaces);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertCount(0, $list[0]->exceptions);

        $this->davEmptyFolder($account, 'Calendar', 'event');
        $this->davAppend($account, 'Calendar', ['mailfilter/event4.ics'], 'event');

        // Test updating an existing occurence, Ned impersonates Jack
        $parser = MailParserTest::getParserForFile('mailfilter/itip2_request.eml', 'john@kolab.org', 'ned@kolab.org', $replaces);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($account, 'Calendar', 'event'));
        $this->assertCount(1, $list[0]->exceptions);
        $this->assertSame('Test Meeting', $list[0]->exceptions[0]->summary);

        Notification::assertNothingSent();
    }
}
