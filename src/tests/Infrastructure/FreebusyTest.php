<?php

namespace Tests\Infrastructure;

use App\DataMigrator\Account;
use Illuminate\Support\Facades\Http;
use Tests\BackendsTrait;
use Tests\TestCase;

class FreebusyTest extends TestCase
{
    use BackendsTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('fbtest@kolab.org', true);
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('fbtest@kolab.org', true);

        parent::setUp();
    }

    /**
     * Test basic freebusy requests
     */
    public function testFreebusyRequest(): void
    {
        $user = $this->getTestUser('fbtest@kolab.org', ['password' => 'simple123'], true);

        $baseUri = \config('services.freebusy.uri');
        $davUri = preg_replace('|^http|', 'dav', \config('services.dav.uri'));

        // First init the account
        $account = new Account(preg_replace('|://|', '://fbtest%40kolab.org:simple123@', $davUri));
        $this->initAccount($account);

        // Append an event into the calendar
        $target_date = \now()->addDays(2)->format('Ymd');
        $replace = [
            '/john@kolab.org/' => $user->email,
            '/20240714/' => $target_date,
        ];
        $this->davAppend($account, 'Calendar', ['event/1.ics'], 'event', $replace);

        // Request free-busy - with authentication
        $response = Http::withOptions(['verify' => false])->baseUrl($baseUri)
            ->withBasicAuth($user->email, 'simple123')
            ->get('/user/' . $user->email);

        $body = $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('BEGIN:VFREEBUSY', $body);
        $this->assertStringContainsString('END:VFREEBUSY', $body);
        $this->assertStringContainsString("FREEBUSY:{$target_date}T170000Z/{$target_date}T180000Z", $body);
        $this->assertStringContainsString('DTSTART:' . \now()->format('Ymd'), $body);

        // Request free-busy - unauthenticated, plus use GET parameters
        $start_date = \now()->subDays(2)->format('c');
        $response = Http::withOptions(['verify' => false])->baseUrl($baseUri)
            ->get('/user/' . $user->email, ['period' => 'P10D', 'start' => $start_date]);

        $body = $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('BEGIN:VFREEBUSY', $body);
        $this->assertStringContainsString('END:VFREEBUSY', $body);
        $this->assertStringContainsString("FREEBUSY:{$target_date}T170000Z/{$target_date}T180000Z", $body);
        $this->assertStringContainsString('DTSTART:' . \now()->subDays(2)->format('Ymd'), $body);
        $this->assertStringContainsString('DTEND:' . \now()->addDays(8)->format('Ymd'), $body);
    }
}
