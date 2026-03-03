<?php

    if (getenv('KOLAB_FREEBUSY_SERVER_DISABLED')) {
        $config['kolab_freebusy_server'] = false;
    } else {
        $config['kolab_freebusy_server'] = getenv('KOLAB_FREEBUSY_SERVER') ?: "https://" . ($_SERVER["HTTP_HOST"] ?? '') . "/freebusy/user/%u";
    }

    if (file_exists(RCUBE_CONFIG_DIR . '/' . ($_SERVER["HTTP_HOST"] ?? null) . '/' . basename(__FILE__))) {
        include_once(RCUBE_CONFIG_DIR . '/' . ($_SERVER["HTTP_HOST"] ?? null) . '/' . basename(__FILE__));
    }

    $config['kolab_cache'] = true;

    $config['kolab_ssl_verify_host'] = false;
    $config['kolab_ssl_verify_peer'] = false;

    $config['kolab_use_subscriptions'] = true;

    $config['activesync_force_subscriptions'] = ['windowsoutlook15' => ['INBOX' => 1, 'Sent' => 1, 'Trash' => 1, 'Spam' => 1, 'Junk Email' => 1, 'Calendar' => 1, 'Contacts' => 1, 'Addressbook' => 1, 'Tasks' => 1, '/dav/calendars/user/.*/Default' => 1, '/dav/addressbooks/user/.*/Default' => 1]];
?>
