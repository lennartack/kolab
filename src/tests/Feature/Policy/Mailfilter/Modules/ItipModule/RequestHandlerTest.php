<?php

namespace Tests\Feature\Policy\Mailfilter\Modules\ItipModule;

use App\DataMigrator\Account;
use App\Policy\Mailfilter\Modules\ItipModule;
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
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_request.eml', 'john@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);

        $list = $this->davList($account, 'Calendar', 'event');
        $this->assertCount(1, $list);
        $this->assertSame('5463F1DDF6DA264A3FC70E7924B729A5-D9F1889254B163F5', $list[0]->uid);
        $this->assertCount(2, $list[0]->attendees);
        $this->assertSame('john@kolab.org', $list[0]->attendees[0]['email']);
        $this->assertSame('NEEDS-ACTION', $list[0]->attendees[0]['partstat']);
        $this->assertSame('ned@kolab.org', $list[0]->attendees[1]['email']);
        $this->assertSame('NEEDS-ACTION', $list[0]->attendees[1]['partstat']);
        $this->assertSame('jack@kolab.org', $list[0]->organizer['email']);

        Notification::assertNothingSent();

        // Test REQUEST to an existing event, and other corner cases
        $replaces = [
            'CN=Ned;PARTSTAT=NEEDS-ACTION' => 'CN=Ned;PARTSTAT=ACCEPTED',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_request.eml', 'john@kolab.org', 'jack@kolab.org', $replaces);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);

        $list = $this->davList($account, 'Calendar', 'event');
        $this->assertCount(1, $list);
        $this->assertSame('5463F1DDF6DA264A3FC70E7924B729A5-D9F1889254B163F5', $list[0]->uid);
        $this->assertCount(2, $list[0]->attendees);
        $this->assertSame('john@kolab.org', $list[0]->attendees[0]['email']);
        $this->assertSame('NEEDS-ACTION', $list[0]->attendees[0]['partstat']);
        $this->assertSame('ned@kolab.org', $list[0]->attendees[1]['email']);
        $this->assertSame('ACCEPTED', $list[0]->attendees[1]['partstat']);
        $this->assertSame('jack@kolab.org', $list[0]->organizer['email']);

        Notification::assertNothingSent();

        // TODO: Test various supported message structures (ItipModule::getItip())
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
        $this->davAppend($account, 'Calendar', ['mailfilter/event3.ics'], 'event');

        // Jack invites John (and Ned) to a new meeting occurrence, the event
        // is already in John's calendar, but has no recurrence exceptions yet
        $parser = MailParserTest::getParserForFile('mailfilter/itip2_request.eml', 'john@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);

        $list = $this->davList($account, 'Calendar', 'event');
        $this->assertCount(1, $list);
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

        // Test updating an existing occurence
        $replaces = [
            'CN=Ned;PARTSTAT=NEEDS-ACTION' => 'CN=Ned;PARTSTAT=ACCEPTED',
        ];
        $parser = MailParserTest::getParserForFile('mailfilter/itip2_request.eml', 'john@kolab.org', 'jack@kolab.org', $replaces);
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);

        $list = $this->davList($account, 'Calendar', 'event');
        $this->assertCount(1, $list);
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

        // Jack sends REQUEST with RRULE containing UNTIL parameter, which is a case when
        // an organizer deletes "this and future" event occurence
        $this->davAppend($account, 'Calendar', ['mailfilter/event5.ics'], 'event');

        $parser = MailParserTest::getParserForFile('mailfilter/itip3_request_rrule_update.eml', 'john@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);

        $list = $this->davList($account, 'Calendar', 'event');
        $list = array_filter($list, static fn ($event) => $event->uid == '5464F1DDF6DA264A3FC70E7924B729A5-333333');
        $event = $list[array_key_first($list)];

        $this->assertCount(1, $list);
        $this->assertCount(2, $attendees = $event->attendees);
        $this->assertSame('john@kolab.org', $attendees[0]['email']);
        $this->assertSame('ACCEPTED', $attendees[0]['partstat']);
        $this->assertSame('ned@kolab.org', $attendees[1]['email']);
        $this->assertSame('NEEDS-ACTION', $attendees[1]['partstat']);
        $this->assertCount(1, $event->exceptions);
        $this->assertSame('20240717T123000', $event->exceptions[0]->recurrenceId);

        Notification::assertNothingSent();
    }
}
