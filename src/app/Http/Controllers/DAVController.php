<?php

namespace App\Http\Controllers;

use App\Http\DAV;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Sabre\DAV\Server;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DAVController extends Controller
{
    /**
     * Register WebDAV route(s)
     */
    public static function registerRoutes(): void
    {
        $root = trim(\config('services.dav.webdav_root'), '/');

        Route::match(
            [
                // Standard HTTP methods
                'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PUT',
                // WebDAV specific methods
                'MOVE', 'COPY', 'MKCOL', 'PROPFIND', 'PROPPATCH', 'REPORT', 'LOCK', 'UNLOCK',
            ],
            $root . '/user/{email}/{path?}',
            [self::class, 'run']
        )
            ->where('path', '.*') // This makes 'path' to match also sub-paths
            ->name('dav');
    }

    /**
     * Handle a WebDAV request
     */
    public function run(Request $request, string $email): Response|StreamedResponse
    {
        $root = trim(\config('services.dav.webdav_root'), '/');

        $sapi = new DAV\Sapi();
        $auth_backend = new DAV\Auth();
        $locks_backend = new DAV\Locks();

        // Initialize the Sabre DAV Server
        $server = new Server(new DAV\Collection(''), $sapi);
        $server->setBaseUri('/' . $root . '/user/' . $email);
        $server->debugExceptions = \config('app.debug');
        $server->enablePropfindDepthInfinity = false;
        $server::$exposeVersion = false;
        // FIXME: Streaming is supposed to improve memory use, but it changes
        // how the response is handled in a way that e.g. for an unknown location you get
        // 207 instead of 404. And our response handling is not working with this either.
        // $server::$streamMultiStatus = true;

        // Log important exceptions catched by Sabre
        $server->on('exception', function ($e) {
            if (!($e instanceof \Sabre\DAV\Exception) || $e->getHTTPCode() == 500) {
                \Log::error($e);
            }
        });

        // Register some plugins
        $server->addPlugin(new \Sabre\DAV\Auth\Plugin($auth_backend));

        // Unauthenticated access doesn't work for us since we require credentials to get access to the data in the first place.
        $acl_plugin = new \Sabre\DAVACL\Plugin();
        $acl_plugin->allowUnauthenticatedAccess = false;
        $server->addPlugin($acl_plugin);

        // The lock manager is responsible for making sure users don't overwrite each others changes.
        $server->addPlugin(new \Sabre\DAV\Locks\Plugin($locks_backend));

        // Intercept some of the garbage files operating systems tend to generate when mounting a WebDAV share
        // $server->addPlugin(new DAV\TempFiles());

        // Finally, process the request
        $server->start();

        return $sapi->getResponse();
    }
}
