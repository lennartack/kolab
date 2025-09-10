<?php

// Kolab Cockpit API location. Defaults to hostname from the HTTP request
$config['kolab_api_url'] = getenv('KOLAB_API_URL');

// Enable logging of Cockpit API requests/responses (to logs/kolab.log)
$config['kolab_api_debug'] = getenv('KOLAB_API_DEBUG');

// List of allowed tasks in helpdesk mode. If empty there's no limits.
// For example, to limit user to the Settings section only: ['settings'].
$config['kolab_helpdesk_allowed_tasks'] = [];

// Type of cache for API requests. Supported values: 'db', 'redis' and 'memcache' or 'memcached'.
$config['kolab_client_cache'] = 'redis';

// Lifetime of cache entries. Possible units: s, m, h, d, w
$config['kolab_client_cache_ttl'] = '10m';

$config['configuration-overlays']['kolabobjects'] = [
    'plugins' => ['kolab_config', 'kolab_folders'],
    'calendar_driver' => 'kolab',
    'fileapi_backend' => 'kolab',
    'kolab_tags_driver' => 'kolab',
    'tasklist_driver' => 'kolab',
    'kolab_addressbook_driver' => 'kolab'
];

$config['configuration-overlays']['kolab4'] = [
    'calendar_driver' => 'caldav',
    'calendar_caldav_server' => getenv('CALENDAR_CALDAV_SERVER') ?: "https://" . ($_SERVER["HTTP_HOST"] ?? null) . "/dav",
    'fileapi_backend' => 'kolabfiles',
    'fileapi_kolabfiles_baseuri' => getenv('FILEAPI_KOLABFILES_BASEURI'),
    'activesync_storage' => 'kolab4',
    'activesync_dav_server' => getenv('CALENDAR_CALDAV_SERVER') ?: "https://" . ($_SERVER["HTTP_HOST"] ?? null) . "/dav",
    'kolab_tags_driver' => 'annotate',
    'kolab_dav_sharing' => 'sharing',
    'tasklist_driver' => 'caldav',
    'tasklist_caldav_server' => getenv('TASKLIST_CALDAV_SERVER') ?: "https://" . ($_SERVER["HTTP_HOST"] ?? null) . "/dav",
    'kolab_addressbook_driver' => 'carddav',
    'kolab_addressbook_carddav_server' => getenv('KOLAB_ADDRESSBOOK_CARDDAV_SERVER') ?: "https://" . ($_SERVER["HTTP_HOST"] ?? null) . "/dav",
];

$config['configuration-overlays']['activesync'] = [
    'plugins' => ['kolab_activesync']
];

$config['configuration-overlays']['2fa'] = [
    'plugins' => ['kolab_2fa']
];

$config['configuration-overlays']['groupware'] = [
    'plugins' => ['calendar', 'kolab_files', 'kolab_addressbook', 'kolab_tags', 'tasklist']
];

$config['configuration-overlays']['groupware-kolabobjects'] = [
    'plugins' => ['calendar', 'kolab_files', 'kolab_addressbook', 'kolab_tags', 'kolab_notes', 'tasklist']
];

if ($disabledPlugins = getenvlist('DISABLED_PLUGINS')) {
    foreach ($config['configuration-overlays'] as $key => $value) {
        if (isset($value['plugins'])) {
            $config['configuration-overlays'][$key]['plugins'] = array_diff($value['plugins'], $disabledPlugins);
        }
    }
}
