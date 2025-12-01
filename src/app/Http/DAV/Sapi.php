<?php

namespace App\Http\DAV;

use Sabre\HTTP\Request;
use Sabre\HTTP\ResponseInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sabre SAPI implementation that uses Laravel request/response for input/output.
 */
class Sapi extends \Sabre\HTTP\Sapi
{
    private static $response;

    /**
     * This static method will create a new Request object, based on the current PHP request.
     */
    public static function getRequest(): Request
    {
        $request = \request();

        $headers = [];
        foreach ($request->headers as $key => $val) {
            if (is_array($val)) {
                $headers[$key] = implode("\n", $val);
            }
        }

        // TODO: For now we create the Sabre's Request object. For better performance
        // and memory usage we should replece it completely with a "direct" access to Laravel's Request.

        $r = new Request($request->method(), $request->path(), $headers);
        $r->setHttpVersion('1.1');
        // $r->setRawServerData($_SERVER);
        $r->setAbsoluteUrl($request->url());
        $r->setBody($request->getContent(true));
        $r->setPostData($request->all());

        return $r;
    }

    /**
     * Laravel Response object getter. To be called after Sapi::sendResponse()
     *
     * This method is for Kolab only, is not part of the Sabre SAPI interface.
     */
    public function getResponse()
    {
        return self::$response;
    }

    /**
     * Override Sabre's Sapi HTTP response sending. Create Laravel's Response
     * to be returned from getResponse()
     */
    public static function sendResponse(ResponseInterface $response): void
    {
        $callback = function () use ($response): void {
            $body = $response->getBody();

            if ($body === null || is_string($body)) {
                echo $body;
            } elseif (is_callable($body)) {
                // FIXME: A callable seems to be used only with streamMultiStatus=true
                $body();
            } elseif (is_resource($body)) {
                $content_length = $response->getHeader('Content-Length');
                $length = is_numeric($content_length) ? (int) $content_length : null;

                if ($length === null) {
                    while (!feof($body)) {
                        echo fread($body, 10 * 1024 * 1024);
                    }
                } else {
                    while ($length > 0 && !feof($body)) {
                        $output = fread($body, min($length, 10 * 1024 * 1024));
                        $length -= strlen($output);
                        echo $output;
                    }
                }
                fclose($body);
            }
        };

        // FIXME: Should we use non-streamed responses for small bodies?

        self::$response = new StreamedResponse($callback, $response->getStatus(), $response->getHeaders());
    }
}
