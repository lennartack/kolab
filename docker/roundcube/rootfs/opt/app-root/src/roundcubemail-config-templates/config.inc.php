<?php

//This check is for some reason required under phpunit
if (!function_exists("getenvlist")) {
    function getenvlist($name) {
        $value = getenv($name);
        return $value == null ? null : explode(",", $value) ;
    }
}

    $config = array();
    $dbUsername = getenv('DB_RC_USERNAME');
    $dbPass = getenv('DB_RC_PASSWORD');
    $dbDatabase = getenv('DB_RC_DATABASE');
    $dbHost = getenv('DB_HOST');
    $config['db_dsnw'] = "mysql://$dbUsername:$dbPass@$dbHost/$dbDatabase";

    $config['session_domain'] = $_SERVER['HTTP_HOST'] ?? '';

    $config['des_key'] = getenv('DES_KEY');
    $config['username_domain'] = getenv('APP_DOMAIN');
    $config['use_secure_urls'] = true;
    $config['use_https'] = true;

    $config['mail_domain'] = '';

    // IMAP Server Settings
    $config['imap_host'] = (getenv('IMAP_TLS') == "true" ? "ssl://" : "") . getenv('IMAP_HOST') . ':' . getenv('IMAP_PORT');
    
    $config['imap_delimiter'] = '/';
    $config['imap_force_lsub'] = true;
    if (getenv('IMAP_TLS') == "true") {
        $config['imap_conn_options'] = [
            'ssl' => [
                    'verify_peer_name' => false,
                    'verify_peer' => false,
                    'allow_self_signed' => true
                ],
            'proxy_protocol' => getenv('IMAP_PROXY_PROTOCOL')
        ];
    }
    $config['proxy_whitelist'] = getenvlist('PROXY_WHITELIST');

    // Caching and storage settings
    $config['imap_cache'] = 'redis';
    $config['imap_cache_ttl'] = '10d';
    $config['dav_cache'] = 'redis';
    $config['dav_cache_ttl'] = '10m';
    // no redis available, cache incompatible with ANNOTATION based tags, shouldn't be required at all.
    $config['messages_cache'] = null;
    $config['session_storage'] = 'redis';
    $config['redis_hosts'] = [getenv('REDIS_HOST') . ':6379:3:' . getenv('REDIS_PASSWORD')];

    // SMTP Server Settings
    if (getenv('SUBMISSION_ENCRYPTION') == "starttls") {
        $config['smtp_host'] = "tls://" . getenv('SUBMISSION_HOST') . ':' . getenv('SUBMISSION_PORT');
    } else {
        $config['smtp_host'] = getenv('SUBMISSION_HOST') . ':' . getenv('SUBMISSION_PORT');
    }
    
    $config['smtp_user'] = '%u';
    $config['smtp_pass'] = '%p';
    $config['smtp_helo_host'] = $_SERVER["HTTP_HOST"] ?? null;
    if (!empty(getenv('SUBMISSION_ENCRYPTION'))) {
        $config['smtp_conn_options'] = [
            'ssl' => [
                'verify_peer_name' => false,
                'verify_peer' => false,
                'allow_self_signed' => true
            ]
        ];
    }

    // Kolab specific defaults
    $config['product_name'] = getenv('PRODUCT_NAME');
    $config['support_url'] = getenv('SUPPORT_URL');
    $config['quota_zero_as_unlimited'] = false;
    $config['login_lc'] = 2;
    $config['auto_create_user'] = true;
    $config['enable_installer'] = false;
    // The SMTP server does not allow empty identities
    $config['mdn_use_from'] = true;

    // Plugins
    $plugins = [
        'acl',
        'archive',
        'kolab',
        // 'calendar',
        'jqueryui',
        'kolab_addressbook',
        // 'kolab_files',
        // 'kolab_tags',
        'managesieve',
        'newmail_notifier',
        'odfviewer',
        'redundant_attachments',
        'vcard_attachments',
        // 'tasklist',
        'enigma',
        // contextmenu must be after kolab_addressbook (#444)
        'contextmenu',
    ];

    if ($disabledPlugins = getenvlist('DISABLED_PLUGINS')) {
        $plugins = array_diff($plugins, $disabledPlugins);
    }

    if ($extraPlugins = getenvlist('EXTRA_PLUGINS')) {
        $plugins = array_merge($plugins, $extraPlugins);
    }

    $config['plugins'] = $plugins;

    // Do not show deleted messages, mark deleted messages as read,
    // and move deleted messages to the Trash (instead of flagging as deleted)
    $config['skip_deleted'] = true;
    $config['read_when_deleted'] = true;
    $config['flag_for_deletion'] = false;
    $config['delete_always'] = true;


    $config['dont_override'] = [
        'skip_deleted',
        'read_when_deleted',
        'flag_for_deletion',
        'delete_always',
    ];

    $config['session_lifetime'] = 180;
    $config['password_charset'] = 'UTF-8';
    $config['useragent'] = 'Kolab 16/Roundcube ' . RCUBE_VERSION;

    $config['message_sort_col'] = 'date';

    $config['spellcheck_engine'] = 'pspell';
    $config['spellcheck_dictionary'] = true;
    $config['spellcheck_ignore_caps'] = true;
    $config['spellcheck_ignore_nums'] = true;
    $config['spellcheck_ignore_syms'] = true;
    $config['spellcheck_languages'] = array(
            'da' => 'Dansk',
            'de' => 'Deutsch',
            'en' => 'English',
            'es' => 'Español',
            'fr' => 'Français',
            'it' => 'Italiano',
            'nl' => 'Nederlands',
            'pt' => 'Português',
            'ru' => 'Русский',
            'sv' => 'Svenska'
        );

    $config['undo_timeout'] = 10;
    $config['upload_progress'] = 2;
    $config['address_template'] = '{street}<br/>{locality} {zipcode}<br/>{country} {region}';
    $config['preview_pane'] = true;
    $config['preview_pane_mark_read'] = 0;

    $config['autoexpand_threads'] = 2;
    $config['top_posting'] = 0;
    $config['sig_above'] = false;
    $config['mdn_requests'] = 0;
    $config['mdn_default'] = false;
    $config['dsn_default'] = false;
    $config['reply_same_folder'] = false;

    if (file_exists(RCUBE_CONFIG_DIR . '/' . ($_SERVER["HTTP_HOST"] ?? null) . '/' . basename(__FILE__))) {
        include_once(RCUBE_CONFIG_DIR . '/' . ($_SERVER["HTTP_HOST"] ?? null) . '/' . basename(__FILE__));
    }

    // Re-apply mandatory settings here.

    $config['debug_level'] = 1;
    $config['devel_mode'] = false;
    $config['log_driver'] = getenv('LOG_DRIVER');
    //$config['per_user_logging'] = (getenv('PER_USER_LOGGING') == "true");
    $config['log_date_format'] = 'd-M-Y H:i:s,u O';
    $config['smtp_log'] = false;
    $config['log_logins'] = true;
    $config['log_session'] = false;
    //$config['sql_debug'] = getenv('SQL_DEBUG');
    //$config['memcache_debug'] = getenv('MEMCACHE_DEBUG');
    //$config['imap_debug'] = getenv('IMAP_DEBUG');
    //$config['smtp_debug'] = getenv('SMTP_DEBUG');
    //$config['dav_debug'] = getenv('DAV_DEBUG');

    $config['skin'] = getenv('SKIN');
    if (!empty(getenvlist('SKINS_ALLOWED'))) {
        $config['skins_allowed'] = getenvlist('SKINS_ALLOWED');
    }
    $config['skin_include_php'] = false;
    if (getenv('FORCE_SKIN') == "true") {
        $config['dont_override'][] = 'skin';
    }
    $config['mime_magic'] = null;
    $config['im_identify_path'] = '/usr/bin/identify';
    $config['im_convert_path'] = '/usr/bin/convert';
    $config['log_dir'] = 'logs/';
    #$config['temp_dir'] = '/var/lib/roundcubemail/';

    $config['create_default_folders'] = true;
    // Some additional default folders (archive plugin)
    $config['archive_mbox'] = 'Archive';
    // The Kolab daemon by default creates 'Spam'
    $config['junk_mbox'] = 'Spam';

    // $config['address_book_type'] = 'ldap';
    $config['autocomplete_min_length'] = 3;
    $config['autocomplete_threads'] = 0;
    $config['autocomplete_max'] = 15;

    // Disable the default addressbook and use the dav addressbook by default
    $config['address_book_type'] = '';

    $config['autocomplete_single'] = true;

    $config['htmleditor'] = 0;

    $config['kolab_http_request'] = Array(
            'ssl_verify_host' => false,
            'ssl_verify_peer' => false,
        );

    if (getenv('DISABLE_SSO') == "true") {
        $config['oauth_provider'] = null;
    } else {
        $config['oauth_provider'] = 'generic';
    }
    $config['oauth_provider_name'] = 'Kolab SSO';
    $config['oauth_client_id'] = getenv('PASSPORT_WEBMAIL_SSO_CLIENT_ID');
    $config['oauth_client_secret'] = getenv('PASSPORT_WEBMAIL_SSO_CLIENT_SECRET');
    $config['oauth_auth_uri'] = getenv('OAUTH_AUTH_URI') ?: 'https://' . ($_SERVER['HTTP_HOST'] ?? null) . '/oauth/authorize';
    $config['oauth_token_uri'] = getenv('OAUTH_TOKEN_URI') ?: 'http://localhost:8000/oauth/token';
    $config['oauth_redirect_uri'] = getenv('OAUTH_REDIRECT_URI') ?: 'https://' . ($_SERVER['HTTP_HOST'] ?? null) . '/roundcubemail/index.php/login/oauth';

    $config['oauth_scope'] = 'email openid auth.token';
    $config['oauth_password_claim'] = 'auth.token';
    $config['oauth_login_redirect'] = (getenv('OAUTH_LOGIN_REDIRECT') == "true");

    @include('kolab_syncroton.inc.php');
    @include('chwala.inc.php');

?>
