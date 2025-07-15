<?php

namespace App\Policy\Mailfilter;

use App\User;

class MailParser
{
    protected $stream;
    protected string $debug_prefix = '';
    protected int $start = 0;
    protected ?int $end;
    protected ?int $bodyPosition;
    protected bool $modified = false;
    protected string $recipient = '';
    protected string $sender = '';
    protected ?User $user = null;

    protected ?string $ctype = null;
    protected array $ctypeParams = [];
    protected array $headers = [];
    protected ?array $parts = null;
    protected array $validHeaders = [
        'content-transfer-encoding',
        'content-type',
        'from',
        'message-id',
        'subject',
    ];

    /**
     * Class constructor.
     *
     * @param resource $stream Mail content stream
     * @param int      $start  Start position in the stream
     * @param ?int     $end    End position in the stream
     */
    public function __construct($stream, int $start = 0, ?int $end = null)
    {
        $this->stream = $stream;
        $this->start = $start;
        $this->end = $end;

        $this->parseHeaders();
    }

    /**
     * Dump stream to a string
     */
    public function dumpStream(): string
    {
        $pos = ftell($this->stream);
        rewind($this->stream);
        $str = stream_get_contents($this->stream);
        fseek($this->stream, $pos);
        return $str;
    }

    /**
     * Log a debug message
     */
    public function debug(string $message): void
    {
        if ($this->debug_prefix) {
            $message = $this->debug_prefix . $message;
        }

        \Log::debug($message);
    }

    /**
     * Get mail header
     */
    public function getHeader($name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Get content type
     */
    public function getContentType(): ?string
    {
        return $this->ctype;
    }

    /**
     * Get the message (or part) body
     *
     * @param ?int $part_id Part identifier
     */
    public function getBody($part_id = null): string
    {
        // TODO: Let's start with a string result, but we might need to use streams

        // get the whole message body
        if (!is_int($part_id)) {
            $result = '';
            $position = $this->bodyPosition;

            fseek($this->stream, $this->bodyPosition);

            while (($line = fgets($this->stream, 2048)) !== false) {
                $position += strlen($line);

                $result .= $line;

                if ($this->end && $position >= $this->end) {
                    break;
                }
            }

            if (str_ends_with($result, "\r\n")) {
                $result = substr($result, 0, -2);
            }

            return \rcube_mime::decode($result, $this->headers['content-transfer-encoding'] ?? null);
        }

        // get the part's body
        $part = $this->getParts()[$part_id] ?? null;

        if (!$part) {
            throw new \Exception("Invalid body identifier");
        }

        return $part->getBody();
    }

    /**
     * Returns start position (in the stream) of the body part
     */
    public function getBodyPosition(): int
    {
        return $this->bodyPosition;
    }

    /**
     * Get mail Message-Id header
     */
    public function getMessageId(): ?string
    {
        return $this->getHeader('message-id');
    }

    /**
     * Returns email address of the recipient
     */
    public function getRecipient(): string
    {
        // TODO: We need to pass the target mailbox from Postfix in some way
        // Delivered-To header or a HTTP request header? or in the URL?

        return $this->recipient;
    }

    /**
     * Return the current mail structure parts (top-level only)
     */
    public function getParts()
    {
        if ($this->parts === null) {
            $this->parts = [];

            if (!empty($this->ctypeParams['boundary']) && str_starts_with($this->ctype, 'multipart/')) {
                $start_line = '--' . $this->ctypeParams['boundary'] . "\r\n";
                $end_line = '--' . $this->ctypeParams['boundary'] . "--\r\n";
                $position = $this->bodyPosition;
                $part_position = null;

                fseek($this->stream, $position);

                while (($line = fgets($this->stream, 2048)) !== false) {
                    $position += strlen($line);

                    if ($line == $start_line) {
                        if ($part_position) {
                            $this->addPart($part_position, $position - strlen($start_line));
                        }

                        $part_position = $position;
                    } elseif ($line == $end_line) {
                        if ($part_position) {
                            $this->addPart($part_position, $position - strlen($end_line));
                            $part_position = $position;
                        }

                        break;
                    }

                    if ($this->end && $position >= $this->end) {
                        break;
                    }
                }
            }
        }

        return $this->parts;
    }

    /**
     * Returns email address of the sender (envelope sender)
     */
    public function getSender(): string
    {
        return $this->sender;
    }

    /**
     * Returns start position of the message/part
     */
    public function getStart(): int
    {
        return $this->start;
    }

    /**
     * Returns end position of the message/part
     */
    public function getEnd(): ?int
    {
        return $this->end;
    }

    /**
     * Return the mail content stream
     */
    public function getStream()
    {
        fseek($this->stream, $this->start);

        return $this->stream;
    }

    /**
     * Returns User object of the recipient
     */
    public function getUser(): ?User
    {
        if ($this->user === null) {
            $this->user = User::where('email', $this->getRecipient())->firstOrFail();
        }

        return $this->user;
    }

    /**
     * Indicate if the mail content has been modified
     */
    public function isModified(): bool
    {
        return $this->modified;
    }

    /**
     * Replace mail part content
     *
     * @param string $body    Body content
     * @param ?int   $part_id Part identifier (NULL to replace the whole message body)
     *
     * @throws \Exception
     */
    public function replaceBody($body, $part_id = null): void
    {
        // TODO: We might need to support stream input
        // TODO: We might need to use different encoding than the original (i.e. set headers)
        // TODO: format=flowed handling for text/plain parts?

        // TODO: This method should work also on parts, but we'd have to reset all parents
        if ($this->start > 0) {
            throw new \Exception("Replacing body supported from the message level only");
        }

        // Replace the whole message body
        if (is_int($part_id)) {
            $part = $this->getParts()[$part_id] ?? null;

            if (!$part) {
                throw new \Exception("Invalid body identifier");
            }
        } else {
            $part = $this;
        }

        $copy = fopen('php://temp', 'r+');

        fseek($this->stream, $this->start);
        stream_copy_to_stream($this->stream, $copy, $part->getBodyPosition());
        fwrite($copy, self::encode($body, $part->getHeader('content-transfer-encoding')));
        fwrite($copy, "\r\n");

        if ($end = $part->getEnd()) {
            stream_copy_to_stream($this->stream, $copy, null, $end);
        }

        $this->stream = $copy;

        // Reset structure information, the message will need to be re-parsed (in some cases)
        $this->parts = null;
        $this->modified = true;
    }

    /**
     * Set debug line prefix for log entries
     */
    public function setDebugPrefix(string $prefix): void
    {
        $this->debug_prefix = $prefix;
    }

    /**
     * Set header value
     *
     * @param string  $header Header name
     * @param ?string $value  Header value
     *
     * @throws \Exception
     */
    public function setHeader(string $header, ?string $value = null): void
    {
        // TODO: This method should work also on parts, but we'd have to reset all parents
        if ($this->start > 0) {
            throw new \Exception("Setting header supported on the message level only");
        }

        $header_name = strtolower($header);
        $header_name_len = strlen($header);

        // Create a new resource stream to copy the content into
        $copy = fopen('php://temp', 'r+');

        // Insert the new header on top
        if (is_string($value)) {
            fwrite($copy, "{$header}: {$value}\r\n");
            $this->headers[$header_name] = $value;
        } else {
            unset($this->headers[$header_name]);
        }

        fseek($this->stream, $position = $this->start);

        // Go throughout all headers and remove the one
        $found = false;
        while (($line = fgets($this->stream, 2048)) !== false) {
            if ($line == "\n" || $line == "\r\n") {
                break;
            }

            if ($line[0] == ' ' || $line[0] == "\t") {
                if (!$found) {
                    fwrite($copy, $line);
                }
            } elseif (strtolower(substr($line, 0, $header_name_len + 1)) == "{$header_name}:") {
                $found = true;
            } else {
                fwrite($copy, $line);
                $found = false;
            }

            $position += strlen($line);
        }

        // Copy the rest of the message
        stream_copy_to_stream($this->stream, $copy, null, $position);

        $this->stream = $copy;
        $this->bodyPosition = $position + 2;

        // Reset structure information, the message will need to be re-parsed (in some cases)
        $this->parts = null;
        $this->modified = true;
    }

    /**
     * Set email address of the recipient
     *
     * @param string|User $recipient Recipient email address or User object
     */
    public function setRecipient($recipient): void
    {
        if ($recipient instanceof User) {
            $this->user = $recipient;
            $this->recipient = $recipient->email;
        } else {
            $this->recipient = $recipient;
        }
    }

    /**
     * Set email address of the sender (envelope sender)
     */
    public function setSender(string $sender): void
    {
        $this->sender = $sender;
    }

    /**
     * Extract mail headers from the mail content
     */
    protected function parseHeaders(): void
    {
        $header = '';
        $position = $this->start;

        fseek($this->stream, $this->start);

        while (($line = fgets($this->stream, 2048)) !== false) {
            $position += strlen($line);

            if ($this->end && $position >= $this->end) {
                $position = $this->end;
                break;
            }

            // All emails MUST have CRLF line endings, per RFC and the parser does not work otherwise.
            if (!str_ends_with($line, "\r\n")) {
                $line = str_replace("\r", 'CR', $line);
                $line = str_replace("\n", 'LF', $line);
                throw new \Exception("Email with non CRLF line-ending detected: {$line}");
            }

            if ($line == "\n" || $line == "\r\n") {
                break;
            }

            $line = rtrim($line, "\r\n");

            if ($line[0] == ' ' || $line[0] == "\t") {
                $header .= ' ' . preg_replace('/^(\s+|\t+)/', '', $line);
            } else {
                $this->addHeader($header);
                $header = $line;
            }
        }

        $this->addHeader($header);
        $this->bodyPosition = $position;
    }

    /**
     * Add parsed header to the headers list
     */
    protected function addHeader($content)
    {
        if (preg_match('/^([a-zA-Z0-9_-]+):/', $content, $matches)) {
            $name = strtolower($matches[1]);

            // Keep only headers we need
            if (in_array($name, $this->validHeaders)) {
                $this->headers[$name] = ltrim(substr($content, strlen($matches[1]) + 1));
            }

            if ($name == 'content-type') {
                $parts = preg_split('/[; ]+/', $this->headers[$name]);
                $this->ctype = strtolower($parts[0]);

                for ($i = 1; $i < count($parts); $i++) {
                    $tokens = explode('=', $parts[$i], 2);
                    if (count($tokens) == 2) {
                        $value = $tokens[1];
                        if (preg_match('/^".*"$/', $value)) {
                            $value = substr($value, 1, -1);
                        }

                        $this->ctypeParams[strtolower($tokens[0])] = $value;
                    }
                }
            }
        }
    }

    /**
     * Add part to the parts list
     */
    protected function addPart($start, $end)
    {
        $pos = ftell($this->stream);

        $this->parts[] = new self($this->stream, $start, $end);

        fseek($this->stream, $pos);
    }

    /**
     * Encode mail body
     */
    protected static function encode($data, $encoding)
    {
        switch ($encoding) {
            case 'quoted-printable':
                return \Mail_mimePart::quotedPrintableEncode($data, 76, "\r\n");
            case 'base64':
                return rtrim(chunk_split(base64_encode($data), 76, "\r\n"));
            case '8bit':
            case '7bit':
            default:
                // TODO: Ensure \r\n line-endings
                return $data;
        }
    }
}
