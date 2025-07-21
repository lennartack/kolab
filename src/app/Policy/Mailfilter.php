<?php

namespace App\Policy;

use App\Policy\Mailfilter\MailParser;
use App\Policy\Mailfilter\Modules;
use App\Policy\Mailfilter\Result;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Mailfilter
{
    public const HEADER = 'X-Kolab-Mailfilter-Action';
    public const HEADER_ACTION_ACCEPT = 'ACCEPT';
    public const HEADER_ACTION_ACCEPT_EMPTY = 'ACCEPT_EMPTY';
    public const HEADER_ACTION_DISCARD = 'DISCARD';
    public const HEADER_ACTION_REJECT = 'REJECT';

    protected static $debugid = '';

    /**
     * SMTP Content Filter
     *
     * @param Request $request the API request
     *
     * @return Response|StreamedResponse The response
     */
    public static function handle(Request $request)
    {
        // How big file we can handle depends on the method. We support both: 1) passing
        // file in the request body, or 2) using multipart/form-data method (standard file upload).
        // 1. For the first case maximum size is defined by:
        //    - w/ Swoole: package_max_length in config/octane.php,
        //      In this case Swoole needs twice as much memory as Laravel w/o Octane
        //      (https://github.com/laravel/octane/issues/959), e.g. 10 MB file under Octane will need 20MB
        //      plus the memory allocated initially (~22MB) = ~42MB
        //      So, to handle 50MB email message we need ~125MB memory (w/o Swoole it'll be ~55MB)
        //      Note: This does not yet consider parsing/modifying the content, but outputing the content
        //      back itself does not require any extra memory.
        //    - w/o Swoole: post_max_size in php.ini.
        // 2. For the second case maximum size is defined by upload_max_filesize in config/octane.php or php.ini.
        //    In this case temp files are used no matter it's under Swoole or not, i.e. memory limit is not an issue.
        //    PHP's post_max_size have to be equal or greater for the w/o Swoole case.

        // TODO: As a performance optimization... Not all mail bodies will need to be parsed.
        // We should consider doing two requests. In first we'd send only mail headers,
        // then we'd send body in another request, but only if needed. For example, a text/plain
        // message from same domain sender does not include an iTip, nor needs a footer injection.

        self::$debugid = dechex((int) str_replace('.', '', explode(' ', microtime())[0]));
        self::debug("Processing message from {$request->sender} to {$request->recipient}...");

        // Email with multiple recipients, which we don't handle at the moment.
        // Likely an outgoing email, so we just accept.
        if (str_contains($request->recipient, ",")) {
            self::debug('Multiple recipients', self::HEADER_ACTION_ACCEPT_EMPTY);
            return response('', 200)
                ->header(self::HEADER, self::HEADER_ACTION_ACCEPT_EMPTY);
        }

        // Find the recipient user
        $user = User::where('email', $request->recipient)->first();

        // Not a local recipient, so e.g. an outgoing email
        if (empty($user)) {
            self::debug('Unknown recipient', self::HEADER_ACTION_ACCEPT_EMPTY);
            return response('', 200)
                ->header(self::HEADER, self::HEADER_ACTION_ACCEPT_EMPTY);
        }

        // Get list of enabled modules for the recipient user
        $modules = self::getModulesConfig($user);

        if (empty($modules)) {
            self::debug('All modules disabled', self::HEADER_ACTION_ACCEPT_EMPTY);
            return response('', 200)
                ->header(self::HEADER, self::HEADER_ACTION_ACCEPT_EMPTY);
        }

        // Handle the mail content from the input
        $files = $request->allFiles();

        if (count($files) == 1) {
            $file = $files[array_key_first($files)];
            if (!$file->isValid()) {
                self::debug('Invalid file upload', 500);
                return response('Invalid file upload', 500);
            }

            $stream = fopen($file->path(), 'r');
        } else {
            $stream = $request->getContent(true);
        }

        // Initialize mail parser
        $parser = new MailParser($stream);
        $parser->setRecipient($user);
        $parser->setDebugPrefix('<' . self::$debugid . '> Mailfilter: ');

        if ($sender = $request->sender) {
            $parser->setSender($sender);
        }

        self::debug("Message-ID: " . ($parser->getMessageId() ?? 'unset'));

        // Execute modules
        foreach ($modules as $module => $config) {
            $module_name = str_replace('Module', '', \class_basename($module));
            self::debug("Executing module {$module_name}...");

            $engine = new $module($config);

            $result = $engine->handle($parser);

            if ($result) {
                if ($result->getStatus() == Result::STATUS_REJECT) {
                    self::debug("Rejected by {$module_name}", self::HEADER_ACTION_REJECT);
                    return response('', 200)
                        ->header(self::HEADER, self::HEADER_ACTION_REJECT);
                }
                if ($result->getStatus() == Result::STATUS_DISCARD) {
                    self::debug("Rejected by {$module_name}", self::HEADER_ACTION_DISCARD);
                    return response('', 200)
                        ->header(self::HEADER, self::HEADER_ACTION_DISCARD);
                }
            }
        }

        // If mail content has been modified, stream it back to Postfix
        if ($parser->isModified()) {
            $response = new StreamedResponse();

            $response->headers->replace([
                'Content-Type' => 'message/rfc822',
                'Content-Disposition' => 'attachment',
                self::HEADER => self::HEADER_ACTION_ACCEPT,
            ]);

            $stream = $parser->getStream();

            $response->setCallback(static function () use ($stream) {
                fpassthru($stream);
                fclose($stream);
            });

            self::debug('Message modified', self::HEADER_ACTION_ACCEPT);
            return $response;
        }

        self::debug('Message intact', self::HEADER_ACTION_ACCEPT_EMPTY);
        return response('', 200)
            ->header(self::HEADER, self::HEADER_ACTION_ACCEPT_EMPTY);
    }

    /**
     * Log a debug message
     */
    protected static function debug(string $message, $status = null): void
    {
        $debug = 'Mailfilter:';

        if (self::$debugid !== '') {
            $debug = '<' . self::$debugid . '> ' . $debug;
        }

        if ($message !== '') {
            $debug .= ' ' . $message;
        }

        if ($status) {
            $debug .= " [{$status}]";
        }

        \Log::debug($debug);
    }

    /**
     * Get list of enabled mail filter modules with their configuration
     */
    protected static function getModulesConfig(User $user): array
    {
        $modules = [
            Modules\TestModule::class => [],
            Modules\ItipModule::class => [],
            Modules\ExternalSenderModule::class => [],
        ];

        // Get user configuration and account policy
        $config = $user->getConfig(true);

        foreach (array_keys($modules) as $class) {
            $module = strtolower(str_replace('Module', '', class_basename($class)));

            // Check if the module is enabled
            if (
                $module != 'test' // Always enable the test module
                && ((isset($config["{$module}_config"]) && $config["{$module}_config"] === false)
                    || (!isset($config["{$module}_config"]) && empty($config["{$module}_policy"])))
            ) {
                unset($modules[$class]);
                continue;
            }

            // Collect module configuration
            foreach ($config as $key => $value) {
                if (str_starts_with($key, "{$module}_")) {
                    $modules[$class][$key] = $value;
                }
            }
        }

        return $modules;
    }
}
