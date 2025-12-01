<?php

namespace App\Http\DAV;

use App\User;
use Sabre\DAV\Auth\Backend\AbstractBasic;

/**
 * Basic Authentication for WebDAV
 */
class Auth extends AbstractBasic
{
    // Make the current user available to all classes
    public static $user;

    /**
     * Authentication Realm.
     *
     * The realm is often displayed by browser clients when showing the
     * authentication dialog.
     *
     * @var string
     */
    protected $realm = 'Kolab/DAV';

    /**
     * This is the prefix that will be used to generate principal urls.
     *
     * @var string
     */
    protected $principalPrefix = 'dav/principals/';

    /**
     * Validates a username and password
     *
     * This method should return true or false depending on if login
     * succeeded.
     *
     * @param string $username
     * @param string $password
     */
    protected function validateUserPass($username, $password): bool
    {
        // Note: For now authenticating user must match the path user

        if (str_contains($username, '@') && $username === $this->getPathUser()) {
            $auth = User::findAndAuthenticate($username, $password);

            if (!empty($auth['user'])) {
                self::$user = $auth['user'];

                // Cyrus DAV principal location
                $this->principalPrefix = 'dav/principals/user/' . $username;
                return true;
            }
        }

        return false;
    }

    /**
     * Extract user (email) from the request path.
     */
    protected static function getPathUser(): string
    {
        $path = \request()->path();
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/';
        $path = substr($path, strlen($root));

        return explode('/', $path)[0];
    }
}
