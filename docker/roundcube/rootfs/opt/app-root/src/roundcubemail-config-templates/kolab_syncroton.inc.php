<?php

// This file lists all ActiveSync-related configuration options

// Enables ActiveSync protocol debuging
//$config['activesync_debug'] = getenv('ACTIVESYNC_DEBUG');

// If specified all ActiveSync-related logs will be saved to this file
// Note: This doesn't change Roundcube Framework log locations
$config['activesync_log_file'] = null;

// Type of ActiveSync cache. Supported values: 'db', 'apc' and 'memcache'.
// Note: This is only for some additional data like timezones mapping.
$config['activesync_cache'] = 'db';

// lifetime of ActiveSync cache
// possible units: s, m, h, d, w
$config['activesync_cache_ttl'] = '1d';

// Type of ActiveSync Auth cache. Supported values: 'db', 'apc' and 'memcache'.
// Note: This is only for username canonification map.
$config['activesync_auth_cache'] = 'db';

// lifetime of ActiveSync Auth cache
// possible units: s, m, h, d, w
$config['activesync_auth_cache_ttl'] = '1d';

// List of global addressbooks (GAL)
// Note: If empty 'autocomplete_addressbooks' setting will be used
$config['activesync_addressbooks'] = array();

// ActiveSync => Roundcube contact fields map for GAL search
/* Default: array(
       'alias'         => 'nickname',
       'company'       => 'organization',
       'displayName'   => 'name',
       'emailAddress'  => 'email',
       'firstName'     => 'firstname',
       'lastName'      => 'surname',
       'mobilePhone'   => 'phone.mobile',
       'office'        => 'office',
       'picture'       => 'photo',
       'phone'         => 'phone',
       'title'         => 'jobtitle',
);
*/
$config['activesync_gal_fieldmap'] = null;

// List of device types that will sync the LDAP addressbook(s) as a normal folder.
// For devices that do not support GAL searching, e.g. Outlook.
// Note: To make the LDAP addressbook sources working we need two additional
//       fields ('uid' and 'changed') specified in the fieldmap array
//       of the LDAP configuration ('ldap_public' option). For example:
//          'uid'     => 'nsuniqueid',
//          'changed' => 'modifytimestamp',
// Examples:
//     array('windowsoutlook')  # enable for Oultook only
//     true                     # enable for all
$config['activesync_gal_sync'] = false;

// GAL cache. As reading all contacts from LDAP may be slow, caching is recommended.
$config['activesync_gal_cache'] = 'db';

// TTL of GAL cache entries. Technically this causes that synchronized
// contacts will not be updated (queried) often than the specified interval.
$config['activesync_gal_cache_ttl'] = '1d';

// List of Roundcube plugins
// WARNING: Not all plugins used in Roundcube can be listed here
$config['activesync_plugins'] = ['kolab'];

// Defines for how many seconds we'll sleep between every
// action for detecting changes in folders. Default: 60
$config['activesync_ping_timeout'] = 60;

// Defines maximum Ping interval in seconds. Default: 900 (15 minutes)
$config['activesync_ping_interval'] = 900;

// We start detecting changes n seconds since the last sync of a folder
// Default: 180
$config['activesync_quiet_time'] = 0;

// Defines maximum number of folders in a single Sync/Ping request. Default: 100.
$config['activesync_max_folders'] = 100;

// When a device is reqistered, by default a set of folders are
// subscribed for syncronization, i.e. INBOX and personal folders with
// defined folder type:
//     mail.drafts, mail.wastebasket, mail.sentitems, mail.outbox,
//     event, event.default,
//     contact, contact.default,
//     task, task.default
// This default set can be extended by adding following values:
//     1 - all subscribed folders in personal namespace
//     2 - all folders in personal namespace
//     4 - all subscribed folders in other users namespace
//     8 - all folders in other users namespace
//    16 - all subscribed folders in shared namespace
//    32 - all folders in shared namespace
$config['activesync_init_subscriptions'] = 21;

// Defines blacklist of devices (device type strings) that do not support folder hierarchies.
// When set to an array folder hierarchies are used on all devices not listed here.
// When set to null an old whitelist approach will be used where we do opposite
// action and enable folder hierarchies only on device types known to support it.
$config['activesync_multifolder_blacklist'] = array();

// Blacklist overwrites for specified object type. If set to an array
// it will have a precedence over 'activesync_multifolder_blacklist' list only for that type.
// Note: Outlook does not support multiple folders for contacts,
//       in that case use $config['activesync_multifolder_blacklist_contact'] = array('windowsoutlook');
$config['activesync_multifolder_blacklist_mail'] = null;
$config['activesync_multifolder_blacklist_event'] = null;
$config['activesync_multifolder_blacklist_contact'] = array('windowsoutlook');
$config['activesync_multifolder_blacklist_note'] = null;
$config['activesync_multifolder_blacklist_task'] = null;

// Enables adding sender name in the From: header of send email
// when a device uses email address only (e.g. iOS devices)
$config['activesync_fix_from'] = false;

// Immediately fail requests from devices marked as broken
$config['activesync_block_broken_devices'] = true;
