<?php

namespace Tests\Unit\Policy\Mailfilter;

use App\Policy\Mailfilter\MailParser;
use Tests\TestCase;

class MailParserTest extends TestCase
{
    /**
     * Test getBody()
     */
    public function testGetBody(): void
    {
        // Simple non-multipart mail
        $parser = self::getParserForFile('mail/1.eml');

        $body = $parser->getBody();

        $this->assertSame('eeea', $body);

        // Multipart/alternative mail
        $parser = $this->getParserForFile('mailfilter/itip1_request.eml');

        $body = $parser->getBody();

        $this->assertSame(1639, strlen($body));

        $body = $parser->getBody(0); // text/plain part

        $this->assertSame(189, strlen($body));
        $this->assertStringStartsWith('*Test Meeting', $body);

        $body = $parser->getBody(1); // text/calendar part

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $body);
        $this->assertStringEndsWith("\r\nEND:VCALENDAR", $body);

        // Non-existing part
        $this->expectException(\Exception::class);
        $parser->getBody(30);
    }

    /**
     * Test access to headers
     */
    public function testGetHeader(): void
    {
        // Multipart/alternative email
        $parser = $this->getParserForFile('mailfilter/itip1_request.eml');

        $this->assertSame('Jack <jack@kolab.org>', $parser->getHeader('from'));
        $this->assertSame('Jack <jack@kolab.org>', $parser->getHeader('From'));
        $this->assertSame('multipart/alternative', $parser->getContentType());

        $part = $parser->getParts()[0]; // text/plain part

        $this->assertSame('quoted-printable', $part->getHeader('content-transfer-encoding'));
        $this->assertSame('text/plain', $part->getContentType());

        $part = $parser->getParts()[1]; // text/calendar part

        $this->assertSame('8bit', $part->getHeader('content-transfer-encoding'));
        $this->assertSame('text/calendar', $part->getContentType());
    }

    /**
     * Test replacing mail content
     */
    public function testReplaceBody(): void
    {
        // Replace whole body in a non-multipart mail
        // Note: The body is base64 encoded
        $parser = self::getParserForFile('mail/1.eml');

        $parser->replaceBody('aa=aa');

        $this->assertSame('aa=aa', $parser->getBody());
        $this->assertTrue($parser->isModified());

        $parser = new MailParser($parser->getStream());

        $this->assertSame('aa=aa', $parser->getBody());
        $this->assertSame('text/plain', $parser->getContentType());
        $this->assertSame('base64', $parser->getHeader('content-transfer-encoding'));

        // Replace text part in multipart/alternative mail
        // Note: The body is quoted-printable encoded
        $parser = $this->getParserForFile('mailfilter/itip1_request.eml');

        $parser->replaceBody('aa=aa', 0);
        $part = $parser->getParts()[0];

        $this->assertSame('aa=aa', $part->getBody());
        $this->assertSame('aa=aa', $parser->getBody(0));
        $this->assertTrue($parser->isModified());

        $parser = new MailParser($parser->getStream());
        $part = $parser->getParts()[0];

        $this->assertSame('aa=aa', $parser->getBody(0));
        $this->assertSame('multipart/alternative', $parser->getContentType());
        $this->assertNull($parser->getHeader('content-transfer-encoding'));
        $this->assertSame('aa=aa', $part->getBody());
        $this->assertSame('text/plain', $part->getContentType());
        $this->assertSame('quoted-printable', $part->getHeader('content-transfer-encoding'));
    }

    /**
     * Test setHeader()
     */
    public function testSetHeader(): void
    {
        // Test changing a header
        $parser = self::getParserForFile('mail/1.eml');

        $this->assertSame('test sync', $parser->getHeader('subject'));
        $this->assertSame('eeea', $parser->getBody());
        $this->assertSame('"Sync 1" <test@kolab.org>', $parser->getHeader('from'));

        $parser->setHeader('Subject', 'new subject');

        $this->assertTrue($parser->isModified());

        $parser = new MailParser($parser->getStream());

        $this->assertSame('new subject', $parser->getHeader('subject'));
        $this->assertSame('eeea', $parser->getBody());
        $this->assertSame('"Sync 1" <test@kolab.org>', $parser->getHeader('from'));

        // Test removing a header
        $parser->setHeader('Subject', null);

        $this->assertTrue($parser->isModified());

        $parser = new MailParser($parser->getStream());

        $this->assertNull($parser->getHeader('subject'));
        $this->assertSame('eeea', $parser->getBody());
        $this->assertSame('"Sync 1" <test@kolab.org>', $parser->getHeader('from'));
    }

    /**
     * Create mail parser instance for specified test message
     */
    public static function getParserForFile(string $file, $recipient = null, $sender = null, $replaces = []): MailParser
    {
        $mail = file_get_contents(__DIR__ . '/../../../data/' . $file);

        foreach ($replaces as $from => $to) {
            $mail = str_replace($from, $to, $mail);
        }

        $mail = str_replace("\n", "\r\n", $mail);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $mail);
        rewind($stream);

        $parser = new MailParser($stream);

        if ($recipient) {
            $parser->setRecipient($recipient);
        }
        if ($sender) {
            $parser->setSender($sender);
        }

        return $parser;
    }
}
