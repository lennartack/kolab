<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;

class ContentSecurityPolicy
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function handle($request, \Closure $next)
    {
        $headers = [
            'csp' => 'Content-Security-Policy',
            'xfo' => 'X-Frame-Options',
        ];

        // Exclude horizon routes, per https://github.com/laravel/horizon/issues/576
        // Exclude WebDAV routes, as it is a service not for a web browser
        $dav_prefix = trim(\config('services.dav.webdav_root'), '/') . '/user/*';

        if ($request->is('horizon*') || $request->is($dav_prefix)) {
            $headers = [];
        }

        $next = $next($request);

        foreach ($headers as $opt => $header) {
            if ($value = \config("app.headers.{$opt}")) {
                $next->headers->set($header, $value);
            }
        }

        return $next;
    }
}
