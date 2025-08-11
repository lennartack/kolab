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

class DelegationTest extends TestCase
{
    use BackendsTrait;

    /**
     * Test REQUEST/REPLY/CANCEL with delegation
     *
     * @group @dav
     */
    public function testDelegation(): void
    {
        // https://datatracker.ietf.org/doc/html/rfc5546#section-3.2.2.3
        // https://datatracker.ietf.org/doc/html/rfc5546#section-4.2.5

        Notification::fake();

        $uri = preg_replace('|^http|', 'dav', \config('services.dav.uri'));
        $jack_account = new Account(preg_replace('|://|', '://jack%40kolab.org:simple123@', $uri));
        $john_account = new Account(preg_replace('|://|', '://john%40kolab.org:simple123@', $uri));
        $joe_account = new Account(preg_replace('|://|', '://joe%40kolab.org:simple123@', $uri));

        $this->davEmptyFolder($jack_account, 'Calendar', 'event');
        $this->davEmptyFolder($john_account, 'Calendar', 'event');
        $this->davEmptyFolder($joe_account, 'Calendar', 'event');

        // Jack invites John
        $this->davAppend($jack_account, 'Calendar', ['mailfilter/event1.ics'], 'event');
        $this->davAppend($john_account, 'Calendar', ['mailfilter/event1.ics'], 'event');

        // John delegates the invitation to Joe
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_request_delegation.eml', 'joe@kolab.org', 'john@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertNull($result);
        $this->assertCount(1, $list = $this->davList($joe_account, 'Calendar', 'event'));
        $this->assertCount(3, $list[0]->attendees);
        $this->assertSame('john@kolab.org', $list[0]->attendees[0]['email']);
        $this->assertSame('DELEGATED', $list[0]->attendees[0]['partstat']);
        $this->assertSame('joe@kolab.org', $list[0]->attendees[0]['delegatedTo']);
        $this->assertSame('joe@kolab.org', $list[0]->attendees[1]['email']);
        $this->assertSame('NEEDS-ACTION', $list[0]->attendees[1]['partstat']);
        $this->assertSame('john@kolab.org', $list[0]->attendees[1]['delegatedFrom']);

        Notification::assertNothingSent();

        // John replies to Jack the organizer
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_reply_delegation1.eml', 'jack@kolab.org', 'john@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertSame(Result::STATUS_DISCARD, $result->getStatus());
        $this->assertCount(1, $list = $this->davList($jack_account, 'Calendar', 'event'));
        $this->assertCount(3, $list[0]->attendees);
        $this->assertSame('john@kolab.org', $list[0]->attendees[0]['email']);
        $this->assertSame('DELEGATED', $list[0]->attendees[0]['partstat']);
        $this->assertSame('joe@kolab.org', $list[0]->attendees[0]['delegatedTo']);
        $this->assertFalse($list[0]->attendees[0]['rsvp']);
        $this->assertSame('ned@kolab.org', $list[0]->attendees[1]['email']);
        $this->assertSame('joe@kolab.org', $list[0]->attendees[2]['email']);
        $this->assertSame('NEEDS-ACTION', $list[0]->attendees[2]['partstat']);
        $this->assertSame('john@kolab.org', $list[0]->attendees[2]['delegatedFrom']);

        Notification::assertCount(1);
        Notification::assertSentTo(
            $user = $this->getTestUser('jack@kolab.org'),
            static function (ItipNotification $notification, array $channels, object $notifiable) use ($user) {
                return $notifiable->id == $user->id
                    && $notification->params->mode == 'reply'
                    && $notification->params->senderEmail == 'john@kolab.org'
                    && $notification->params->senderName == 'John'
                    && $notification->params->comment == 'a reply from John'
                    && $notification->params->partstat == 'DELEGATED'
                    && $notification->params->start == '2024-07-10 10:30'
                    && $notification->params->summary == 'Test Meeting'
                    && empty($notification->params->recurrenceId);
            }
        );

        $this->davEmptyFolder($john_account, 'Calendar', 'event');
        $this->davAppend($john_account, 'Calendar', ['mailfilter/event6.ics'], 'event');

        // Joe replies to John the delegator
        Notification::fake();
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_reply_delegation2.eml', 'john@kolab.org', 'joe@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertSame(Result::STATUS_DISCARD, $result->getStatus());
        $this->assertCount(1, $list = $this->davList($john_account, 'Calendar', 'event'));
        $this->assertCount(3, $list[0]->attendees);
        $this->assertSame('john@kolab.org', $list[0]->attendees[0]['email']);
        $this->assertSame('DELEGATED', $list[0]->attendees[0]['partstat']);
        $this->assertSame('joe@kolab.org', $list[0]->attendees[0]['delegatedTo']);
        $this->assertSame('joe@kolab.org', $list[0]->attendees[1]['email']);
        $this->assertSame('ACCEPTED', $list[0]->attendees[1]['partstat']);
        $this->assertSame('john@kolab.org', $list[0]->attendees[1]['delegatedFrom']);

        Notification::assertCount(1);
        Notification::assertSentTo(
            $user = $this->getTestUser('john@kolab.org'),
            static function (ItipNotification $notification, array $channels, object $notifiable) use ($user) {
                return $notifiable->id == $user->id
                    && $notification->params->mode == 'reply'
                    && $notification->params->senderEmail == 'joe@kolab.org'
                    && $notification->params->senderName == 'Joe'
                    && $notification->params->comment == 'a reply from Joe'
                    && $notification->params->partstat == 'ACCEPTED'
                    && $notification->params->start == '2024-07-10 10:30'
                    && $notification->params->summary == 'Test Meeting'
                    && empty($notification->params->recurrenceId);
            }
        );

        // Joe replies to Jack the organizer
        Notification::fake();
        $parser = MailParserTest::getParserForFile('mailfilter/itip1_reply_delegation2.eml', 'jack@kolab.org', 'joe@kolab.org');
        $module = new ItipModule();
        $result = $module->handle($parser);

        $this->assertSame(Result::STATUS_DISCARD, $result->getStatus());
        $this->assertCount(1, $list = $this->davList($jack_account, 'Calendar', 'event'));
        $this->assertCount(3, $list[0]->attendees);
        $this->assertSame('john@kolab.org', $list[0]->attendees[0]['email']);
        $this->assertSame('DELEGATED', $list[0]->attendees[0]['partstat']);
        $this->assertSame('joe@kolab.org', $list[0]->attendees[0]['delegatedTo']);
        $this->assertSame('ned@kolab.org', $list[0]->attendees[1]['email']);
        $this->assertSame('joe@kolab.org', $list[0]->attendees[2]['email']);
        $this->assertSame('ACCEPTED', $list[0]->attendees[2]['partstat']);
        $this->assertSame('john@kolab.org', $list[0]->attendees[2]['delegatedFrom']);

        Notification::assertCount(1);
        Notification::assertSentTo(
            $user = $this->getTestUser('jack@kolab.org'),
            static function (ItipNotification $notification, array $channels, object $notifiable) use ($user) {
                return $notifiable->id == $user->id
                    && $notification->params->mode == 'reply'
                    && $notification->params->senderEmail == 'joe@kolab.org'
                    && $notification->params->senderName == 'Joe'
                    && $notification->params->comment == 'a reply from Joe'
                    && $notification->params->partstat == 'ACCEPTED'
                    && $notification->params->start == '2024-07-10 10:30'
                    && $notification->params->summary == 'Test Meeting'
                    && empty($notification->params->recurrenceId);
            }
        );
    }
}
