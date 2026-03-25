<?php

/*
 +-------------------------------------------------------------------------+
 | Configuration for the Kolab DAV server                                  |
 |                                                                         |
 | Copyright (C) 2013, Kolab Systems AG                                    |
 |                                                                         |
 | This program is free software: you can redistribute it and/or modify    |
 | it under the terms of the GNU Affero General Public License as          |
 | published by the Free Software Foundation, either version 3 of the      |
 | License, or (at your option) any later version.                         |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the            |
 | GNU Affero General Public License for more details.                     |
 |                                                                         |
 | You should have received a copy of the GNU Affero General Public License|
 | along with this program. If not, see <http://www.gnu.org/licenses/>.    |
 |                                                                         |
 +-------------------------------------------------------------------------+
*/

$config = array();

// The HTTP path to the iRony root directory.
// Set to / if the service is registered as document root for a virtual host
if (getenv('DISABLE_DAV_PREFIX') == "true") {
    $config['base_uri'] = '/';
} else {
    $config['base_uri'] = '/dav/';
}

// User agent string written to kolab storage MIME messages
$config['useragent'] = 'Kolab DAV Server libkolab/' . RCUBE_VERSION;

// Avoid loading the kolab_auth plugin (because of ldap)
$config['kolabdav_plugins'] = [
    'libcalendaring',
    'libkolab'
];

// Type of Auth cache. Supported values: 'db', 'apc' and 'memcache'.
// Note: This is only for username canonification map.
$config['kolabdav_auth_cache'] = 'db';

// lifetime of the Auth cache, possible units: s, m, h, d, w
$config['kolabdav_auth_cache_ttl'] = '1h';

// enable debug console showing the internal function calls triggered
// by http requests. This will write log to /var/log/iRony/console
$config['kolabdav_console'] = false;

// enable logging of full HTTP payload
// (bitmask of these values: 2 = HTTP Requests, 4 = HTTP Responses)
$config['kolabdav_http_log'] = 0;

// expose iTip invitations from email inbox in CalDAV scheduling inbox.
// this will make capable CalDAV clients process event invitations and
// as a result, the invitation messages are removed from the email inbox.
// WARNING: this feature is still experimental and not fully implemented.
// See https://git.kolab.org/T93 for details and implementation status.
$config['kolabdav_caldav_inbox'] = false;
