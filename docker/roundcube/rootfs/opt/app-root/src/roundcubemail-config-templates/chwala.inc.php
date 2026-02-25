<?php

// This file contains Chwala configuration options.
// Real config file must contain or include Roundcube Framework config.

// ------------------------------------------------
// Global settings
// ------------------------------------------------


// Main files source, backend driver which handles
// authentication and configuration of Chwala
// Note: Currently only 'kolab' is supported
if (getenv('KOLABOBJECTS_COMPAT_MODE') == "true") {
    $config['fileapi_backend'] = 'kolab';
    $config['fileapi_plugins'] = ['kolab_folders'];
} else {
    $config['fileapi_backend'] = 'kolabfiles';
    // This is how chwala connects to the kolabfiles backend
    $config['fileapi_kolabfiles_baseuri'] = getenv('FILEAPI_KOLABFILES_BASEURI');
    $config['fileapi_plugins'] = [];
}

// Pre-defined list of external storage sources.
// Here admins can define sources which will be "mounted" into users folder tree
/*
$config['fileapi_sources'] = array(
    'Seafile' => array(
        'driver' => 'seafile',
        'host'   => 'seacloud.cc',
        // when username is set to '%u' current user name and password
        // will be used to authenticate to this storage source
        'username' => '%u',
    ),
    'Public-Files' => array(
        'driver'   => 'webdav',
        'baseuri'  => 'https://some.host.tld/Files',
        'username' => 'admin',
        'password' => 'pass',
    ),
);
*/
// $config['fileapi_sources'] = array(
//     'Public-Files' => array(
//         'driver'   => 'webdav',
//         'baseuri'  => 'https://kolab.local/dav/drive/user/admin@kolab.local/',
//         'username' => '%u',
//         'password' => 'simple123',
//     ),
// );

// Default values for sources configuration dialog.
// Note: use driver names as the array keys.
// Note: %u variable will be resolved to the current username.
/*
$config['fileapi_presets'] = array(
    'seafile' => array(
        'host'     => 'seacloud.cc',
        'username' => '%u',
    ),
    'webdav' => array(
        'baseuri'  => 'https://some.host.tld/Files',
        'username' => '%u',
    ),
);
*/

// Disables listing folders from the backend storage.
// This is useful when you configured an external source(s) and
// you want to use it exclusively, ignoring Kolab folders.
$config['fileapi_backend_storage_disabled'] = false;

// Manticore service URL. Enables use of WebODF collaborative editor.
// Note: this URL should be accessible from Chwala host and Roundcube host as well.
$config['fileapi_manticore'] = null;

// WOPI/Office service URL. Enables use of collaborative editor supporting WOPI.
// Note: this URL should be accessible from the Chwala host
$config['fileapi_wopi_office'] = getenv('FILEAPI_WOPI_OFFICE');

// Name of the user interface skin.
$config['file_api_skin'] = 'default';

// Chwala UI communicates with Chwala API via HTTP protocol
// The URL here is a location of Chwala API service. By default
// the UI location is used with addition of /api/ suffix.
# Force https if we're behind a proxy. Browsers don't allow mixed content.
$config['file_api_url'] = getenv('FILE_API_URL') ?: 'https://' . ($_SERVER['HTTP_HOST'] ?? null) . '/chwala/api/';

// URL for the wopi service to connect back to us (instead of file_api_url)
$config['file_api_server_url'] = getenv('FILE_API_SERVER_URL');

// Type of Chwala cache. Supported values: 'db', 'apc' and 'memcache'.
// Note: This is only for some additional data like WOPI capabilities.
$config['fileapi_cache'] = 'db';

// lifetime of Chwala cache
// possible units: s, m, h, d, w
$config['fileapi_cache_ttl'] = '1d';

// LDAP addressbook that would be searched for user names autocomplete.
// That should be an array refering to the Roundcube's $config['ldap_public']
// array key or complete addressbook configuration array.
// FIXME: replace with non ldap solution
// $config['fileapi_users_source'] = 'kolab_addressbook';

// The LDAP attribute which will be used as ACL user identifier
// $config['fileapi_users_field'] = 'mail';

// The LDAP search filter will be combined with search queries
// $config['fileapi_users_filter'] = '';

// Include groups in searching
// $config['fileapi_groups'] = false;

// Prefix added to the group name to build IMAP ACL identifier
// $config['fileapi_group_prefix'] = 'group:';

// The LDAP attribute (or field name) which will be used as ACL group identifier
// $config['fileapi_group_field'] = 'name';

// ------------------------------------------------
// SeaFile driver settings
// ------------------------------------------------

// Enables SeaFile Web API conversation log
$config['fileapi_seafile_debug'] = false;

// Enables caching of some SeaFile information e.g. folders list
// Note: 'db', 'apc' and 'memcache' are supported
$config['fileapi_seafile_cache'] = 'db';

// Expiration time of SeaFile cache entries
$config['fileapi_seafile_cache_ttl'] = '7d';

// Default SeaFile Web API host
// Note: http:// and https:// (default) prefixes can be used here
$config['fileapi_seafile_host'] = 'localhost';

// Enables SSL certificates validation when connecting
// with any SeaFile server
$config['fileapi_seafile_ssl_verify_host'] = false;
$config['fileapi_seafile_ssl_verify_peer'] = false;

// To support various Seafile configurations when fetching a file
// from Seafile server we proxy it via Chwala server.
// Enable this option to allow direct downloading of files
// from Seafile server to user browser.
$config['fileapi_seafile_allow_redirects'] = false;

// ------------------------------------------------
// WebDAV driver settings
// ------------------------------------------------

// Default URI location for WebDAV storage
$config['fileapi_webdav_baseuri'] = 'https://imap/dav';


?>
