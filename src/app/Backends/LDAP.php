<?php

namespace App\Backends;

use App\Domain;
use App\Group;
use App\Resource;
use App\SharedFolder;
use App\User;
use App\Utils;

class LDAP
{
    /** @var ?\Net_LDAP3 LDAP connection object */
    protected static $ldap;

    /**
     * Starts a new LDAP connection that will be used by all methods
     * until you call self::disconnect() explicitely. Normally every
     * method uses a separate connection.
     *
     * @throws \Exception
     */
    public static function connect(): void
    {
        if (empty(self::$ldap)) {
            $config = self::getConfig();
            self::$ldap = self::initLDAP($config);
        }
    }

    /**
     * Close the connection created by self::connect()
     */
    public static function disconnect(): void
    {
        if (!empty(self::$ldap)) {
            self::$ldap->close();
            self::$ldap = null;
        }
    }

    /**
     * Validates that ldap is available as configured.
     *
     * @throws \Exception
     */
    public static function healthcheck(): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $mgmtRootDN = \config('services.ldap.admin.root_dn');
        $hostedRootDN = \config('services.ldap.hosted.root_dn');

        $result = $ldap->search($mgmtRootDN, '', 'base');
        if (!$result || $result->count() != 1) {
            self::throwException($ldap, "Failed to find the configured management domain {$mgmtRootDN}");
        }

        $result = $ldap->search($hostedRootDN, '', 'base');
        if (!$result || $result->count() != 1) {
            self::throwException($ldap, "Failed to find the configured hosted domain {$hostedRootDN}");
        }
    }

    /**
     * Create a domain in LDAP.
     *
     * @param Domain $domain the domain to create
     *
     * @throws \Exception
     */
    public static function createDomain(Domain $domain): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $mgmtRootDN = \config('services.ldap.admin.root_dn');
        $hostedRootDN = \config('services.ldap.hosted.root_dn');

        $domainBaseDN = "ou={$domain->namespace},{$hostedRootDN}";

        $aci = [
            '(targetattr = "*")'
            . '(version 3.0; acl "Deny Unauthorized"; deny (all)'
            . '(userdn != "ldap:///uid=kolab-service,ou=Special Users,' . $mgmtRootDN
            . ' || ldap:///ou=People,' . $domainBaseDN . '??sub?(objectclass=inetorgperson)") '
            . 'AND NOT roledn = "ldap:///cn=kolab-admin,' . $mgmtRootDN . '";)',

            '(targetattr != "userPassword")'
            . '(version 3.0;acl "Search Access";allow (read,compare,search)'
            . '(userdn = "ldap:///uid=kolab-service,ou=Special Users,' . $mgmtRootDN
            . ' || ldap:///ou=People,' . $domainBaseDN . '??sub?(objectclass=inetorgperson)");)',

            '(targetattr = "*")'
            . '(version 3.0;acl "Kolab Administrators";allow (all)'
            . '(roledn = "ldap:///cn=kolab-admin,' . $domainBaseDN
            . ' || ldap:///cn=kolab-admin,' . $mgmtRootDN . '");)',
        ];

        $entry = [
            'aci' => $aci,
            'associateddomain' => $domain->namespace,
            'inetdomainbasedn' => $domainBaseDN,
            'objectclass' => [
                'top',
                'domainrelatedobject',
                'inetdomain',
            ],
        ];

        $dn = "associateddomain={$domain->namespace},{$config['domain_base_dn']}";

        self::setDomainAttributes($domain, $entry);

        if (!$ldap->get_entry($dn)) {
            self::addEntry(
                $ldap,
                $dn,
                $entry,
                "Failed to create domain {$domain->namespace} in LDAP (" . __LINE__ . ")"
            );
        }

        // create ou, roles, ous
        $entry = [
            'description' => $domain->namespace,
            'objectclass' => [
                'top',
                'organizationalunit',
            ],
            'ou' => $domain->namespace,
        ];

        $entry['aci'] = [
            '(targetattr = "*")'
            . '(version 3.0;acl "Deny Unauthorized"; deny (all)'
            . '(userdn != "ldap:///uid=kolab-service,ou=Special Users,' . $mgmtRootDN
            . ' || ldap:///ou=People,' . $domainBaseDN . '??sub?(objectclass=inetorgperson)") '
            . 'AND NOT roledn = "ldap:///cn=kolab-admin,' . $mgmtRootDN . '";)',

            '(targetattr != "userPassword")'
            . '(version 3.0;acl "Search Access";allow (read,compare,search,write)'
            . '(userdn = "ldap:///uid=kolab-service,ou=Special Users,' . $mgmtRootDN
            . ' || ldap:///ou=People,' . $domainBaseDN . '??sub?(objectclass=inetorgperson)");)',

            '(targetattr = "*")'
            . '(version 3.0;acl "Kolab Administrators";allow (all)'
            . '(roledn = "ldap:///cn=kolab-admin,' . $domainBaseDN
            . ' || ldap:///cn=kolab-admin,' . $mgmtRootDN . '");)',

            '(target = "ldap:///ou=*,' . $domainBaseDN . '")'
            . '(targetattr="objectclass || aci || ou")'
            . '(version 3.0;acl "Allow Domain sub-OU Registration"; allow (add)'
            . '(userdn = "ldap:///uid=kolab-service,ou=Special Users,' . $mgmtRootDN . '");)',

            '(target = "ldap:///uid=*,ou=People,' . $domainBaseDN . '")(targetattr="*")'
            . '(version 3.0;acl "Allow Domain First User Registration"; allow (add)'
            . '(userdn = "ldap:///uid=kolab-service,ou=Special Users,' . $mgmtRootDN . '");)',

            '(target = "ldap:///cn=*,' . $domainBaseDN . '")(targetattr="objectclass || cn")'
            . '(version 3.0;acl "Allow Domain Role Registration"; allow (add)'
            . '(userdn = "ldap:///uid=kolab-service,ou=Special Users,' . $mgmtRootDN . '");)',
        ];

        if (!$ldap->get_entry($domainBaseDN)) {
            self::addEntry(
                $ldap,
                $domainBaseDN,
                $entry,
                "Failed to create domain {$domain->namespace} in LDAP (" . __LINE__ . ")"
            );
        }

        foreach (['Groups', 'People', 'Resources', 'Shared Folders'] as $item) {
            $itemDN = "ou={$item},{$domainBaseDN}";
            if (!$ldap->get_entry($itemDN)) {
                $itemEntry = [
                    'ou' => $item,
                    'description' => $item,
                    'objectclass' => [
                        'top',
                        'organizationalunit',
                    ],
                ];

                self::addEntry(
                    $ldap,
                    $itemDN,
                    $itemEntry,
                    "Failed to create domain {$domain->namespace} in LDAP (" . __LINE__ . ")"
                );
            }
        }

        foreach (['kolab-admin'] as $item) {
            $itemDN = "cn={$item},{$domainBaseDN}";
            if (!$ldap->get_entry($itemDN)) {
                $itemEntry = [
                    'cn' => $item,
                    'description' => "{$item} role",
                    'objectclass' => [
                        'top',
                        'ldapsubentry',
                        'nsmanagedroledefinition',
                        'nsroledefinition',
                        'nssimpleroledefinition',
                    ],
                ];

                self::addEntry(
                    $ldap,
                    $itemDN,
                    $itemEntry,
                    "Failed to create domain {$domain->namespace} in LDAP (" . __LINE__ . ")"
                );
            }
        }

        // TODO: Assign kolab-admin role to the owner?

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Create a group in LDAP.
     *
     * @param Group $group the group to create
     *
     * @throws \Exception
     */
    public static function createGroup(Group $group): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $domainName = explode('@', $group->email, 2)[1];
        $cn = $ldap->quote_string($group->name);
        $dn = "cn={$cn}," . self::baseDN($ldap, $domainName, 'Groups');

        $entry = [
            'mail' => $group->email,
            'objectclass' => [
                'top',
                'groupofuniquenames',
                'kolabgroupofuniquenames',
            ],
        ];

        if (!self::getGroupEntry($ldap, $group->email)) {
            self::setGroupAttributes($ldap, $group, $entry);

            self::addEntry(
                $ldap,
                $dn,
                $entry,
                "Failed to create group {$group->email} in LDAP (" . __LINE__ . ")"
            );
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Create a resource in LDAP.
     *
     * @param Resource $resource the resource to create
     *
     * @throws \Exception
     */
    public static function createResource(Resource $resource): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $domainName = explode('@', $resource->email, 2)[1];
        $cn = $ldap->quote_string($resource->name);
        $dn = "cn={$cn}," . self::baseDN($ldap, $domainName, 'Resources');

        $entry = [
            'mail' => $resource->email,
            'objectclass' => [
                'top',
                'kolabresource',
                'kolabsharedfolder',
                'mailrecipient',
            ],
            'kolabfoldertype' => 'event',
        ];

        if (!self::getResourceEntry($ldap, $resource->email)) {
            self::setResourceAttributes($ldap, $resource, $entry);

            self::addEntry(
                $ldap,
                $dn,
                $entry,
                "Failed to create resource {$resource->email} in LDAP (" . __LINE__ . ")"
            );
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Create a shared folder in LDAP.
     *
     * @param SharedFolder $folder the shared folder to create
     *
     * @throws \Exception
     */
    public static function createSharedFolder(SharedFolder $folder): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $domainName = explode('@', $folder->email, 2)[1];
        $cn = $ldap->quote_string($folder->name);
        $dn = "cn={$cn}," . self::baseDN($ldap, $domainName, 'Shared Folders');

        $entry = [
            'mail' => $folder->email,
            'objectclass' => [
                'top',
                'kolabsharedfolder',
                'mailrecipient',
            ],
        ];

        if (!self::getSharedFolderEntry($ldap, $folder->email)) {
            self::setSharedFolderAttributes($ldap, $folder, $entry);

            self::addEntry(
                $ldap,
                $dn,
                $entry,
                "Failed to create shared folder {$folder->id} in LDAP (" . __LINE__ . ")"
            );
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Create a user in LDAP.
     *
     * Only need to add user if in any of the local domains? Figure that out here for now. Should
     * have Context-Based Access Controls before the job is queued though, probably.
     *
     * Use one of three modes;
     *
     * 1) The authenticated user account.
     *
     *    * Only valid if the authenticated user is a domain admin.
     *    * We don't know the originating user here.
     *    * We certainly don't have its password anymore.
     *
     * 2) The hosted kolab account.
     *
     * 3) The Directory Manager account.
     *
     * @param User $user the user account to create
     *
     * @throws \Exception
     */
    public static function createUser(User $user): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $entry = [
            'objectclass' => [
                'top',
                'inetorgperson',
                'inetuser',
                'kolabinetorgperson',
                'mailrecipient',
                'person',
            ],
            'mail' => $user->email,
            'uid' => $user->email,
            'nsroledn' => [],
        ];

        if (!self::getUserEntry($ldap, $user->email, $dn)) {
            if (empty($dn)) {
                self::throwException($ldap, "Failed to create user {$user->email} in LDAP (" . __LINE__ . ")");
            }

            self::setUserAttributes($user, $entry);

            self::addEntry(
                $ldap,
                $dn,
                $entry,
                "Failed to create user {$user->email} in LDAP (" . __LINE__ . ")"
            );
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Delete a domain from LDAP.
     *
     * @param Domain $domain The domain to delete
     *
     * @throws \Exception
     */
    public static function deleteDomain(Domain $domain): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $domainBaseDN = self::baseDN($ldap, $domain->namespace);

        if ($ldap->get_entry($domainBaseDN)) {
            $result = $ldap->delete_entry_recursive($domainBaseDN);

            if (!$result) {
                self::throwException(
                    $ldap,
                    "Failed to delete domain {$domain->namespace} from LDAP (" . __LINE__ . ")"
                );
            }
        }

        if ($ldap_domain = $ldap->find_domain($domain->namespace)) {
            if ($ldap->get_entry($ldap_domain['dn'])) {
                $result = $ldap->delete_entry($ldap_domain['dn']);

                if (!$result) {
                    self::throwException(
                        $ldap,
                        "Failed to delete domain {$domain->namespace} from LDAP (" . __LINE__ . ")"
                    );
                }
            }
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Delete a group from LDAP.
     *
     * @param Group $group the group to delete
     *
     * @throws \Exception
     */
    public static function deleteGroup(Group $group): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        if (self::getGroupEntry($ldap, $group->email, $dn)) {
            $result = $ldap->delete_entry($dn);

            if (!$result) {
                self::throwException(
                    $ldap,
                    "Failed to delete group {$group->email} from LDAP (" . __LINE__ . ")"
                );
            }
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Delete a resource from LDAP.
     *
     * @param Resource $resource the resource to delete
     *
     * @throws \Exception
     */
    public static function deleteResource(Resource $resource): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        if (self::getResourceEntry($ldap, $resource->email, $dn)) {
            $result = $ldap->delete_entry($dn);

            if (!$result) {
                self::throwException(
                    $ldap,
                    "Failed to delete resource {$resource->email} from LDAP (" . __LINE__ . ")"
                );
            }
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Delete a shared folder from LDAP.
     *
     * @param SharedFolder $folder the shared folder to delete
     *
     * @throws \Exception
     */
    public static function deleteSharedFolder(SharedFolder $folder): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        if (self::getSharedFolderEntry($ldap, $folder->email, $dn)) {
            $result = $ldap->delete_entry($dn);

            if (!$result) {
                self::throwException(
                    $ldap,
                    "Failed to delete shared folder {$folder->id} from LDAP (" . __LINE__ . ")"
                );
            }
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Delete a user from LDAP.
     *
     * @param User $user the user account to delete
     *
     * @throws \Exception
     */
    public static function deleteUser(User $user): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        if (self::getUserEntry($ldap, $user->email, $dn)) {
            $result = $ldap->delete_entry($dn);

            if (!$result) {
                self::throwException(
                    $ldap,
                    "Failed to delete user {$user->email} from LDAP (" . __LINE__ . ")"
                );
            }
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Get a domain data from LDAP.
     *
     * @param string $namespace The domain name
     *
     * @return array|false|null
     *
     * @throws \Exception
     */
    public static function getDomain(string $namespace)
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $ldapDomain = $ldap->find_domain($namespace);

        if ($ldapDomain) {
            $domain = $ldap->get_entry($ldapDomain['dn']);
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }

        return $domain ?? null;
    }

    /**
     * Get a group data from LDAP.
     *
     * @param string $email the group email
     *
     * @return array|false|null
     *
     * @throws \Exception
     */
    public static function getGroup(string $email)
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $group = self::getGroupEntry($ldap, $email, $dn);

        if (empty(self::$ldap)) {
            $ldap->close();
        }

        return $group;
    }

    /**
     * Get a resource data from LDAP.
     *
     * @param string $email the resource email
     *
     * @return array|false|null
     *
     * @throws \Exception
     */
    public static function getResource(string $email)
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $resource = self::getResourceEntry($ldap, $email, $dn);

        if (empty(self::$ldap)) {
            $ldap->close();
        }

        return $resource;
    }

    /**
     * Get a shared folder data from LDAP.
     *
     * @param string $email the resource email
     *
     * @return array|false|null
     *
     * @throws \Exception
     */
    public static function getSharedFolder(string $email)
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $folder = self::getSharedFolderEntry($ldap, $email, $dn);

        if (empty(self::$ldap)) {
            $ldap->close();
        }

        return $folder;
    }

    /**
     * Get a user data from LDAP.
     *
     * @param string $email the user email
     *
     * @return array|false|null
     *
     * @throws \Exception
     */
    public static function getUser(string $email)
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $user = self::getUserEntry($ldap, $email, $dn, true);

        if (empty(self::$ldap)) {
            $ldap->close();
        }

        return $user;
    }

    /**
     * Update a domain in LDAP.
     *
     * @param Domain $domain the domain to update
     *
     * @throws \Exception
     */
    public static function updateDomain(Domain $domain): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $ldapDomain = $ldap->find_domain($domain->namespace);

        if (!$ldapDomain) {
            self::throwException(
                $ldap,
                "Failed to update domain {$domain->namespace} in LDAP (domain not found)"
            );
        }

        $oldEntry = $ldap->get_entry($ldapDomain['dn']);
        $newEntry = $oldEntry;

        self::setDomainAttributes($domain, $newEntry);

        if (array_key_exists('inetdomainstatus', $newEntry)) {
            $newEntry['inetdomainstatus'] = (string) $newEntry['inetdomainstatus'];
        }

        $result = $ldap->modify_entry($ldapDomain['dn'], $oldEntry, $newEntry);

        if (!is_array($result)) {
            self::throwException(
                $ldap,
                "Failed to update domain {$domain->namespace} in LDAP (" . __LINE__ . ")"
            );
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Update a group in LDAP.
     *
     * @param Group $group The group to update
     *
     * @throws \Exception
     */
    public static function updateGroup(Group $group): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $newEntry = $oldEntry = self::getGroupEntry($ldap, $group->email, $dn);

        if (empty($oldEntry)) {
            self::throwException(
                $ldap,
                "Failed to update group {$group->email} in LDAP (group not found)"
            );
        }

        self::setGroupAttributes($ldap, $group, $newEntry);

        $result = $ldap->modify_entry($dn, $oldEntry, $newEntry);

        if (!is_array($result)) {
            self::throwException(
                $ldap,
                "Failed to update group {$group->email} in LDAP (" . __LINE__ . ")"
            );
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Update a resource in LDAP.
     *
     * @param Resource $resource The resource to update
     *
     * @throws \Exception
     */
    public static function updateResource(Resource $resource): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $newEntry = $oldEntry = self::getResourceEntry($ldap, $resource->email, $dn);

        if (empty($oldEntry)) {
            self::throwException(
                $ldap,
                "Failed to update resource {$resource->email} in LDAP (resource not found)"
            );
        }

        self::setResourceAttributes($ldap, $resource, $newEntry);

        $result = $ldap->modify_entry($dn, $oldEntry, $newEntry);

        if (!is_array($result)) {
            self::throwException(
                $ldap,
                "Failed to update resource {$resource->email} in LDAP (" . __LINE__ . ")"
            );
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Update a shared folder in LDAP.
     *
     * @param SharedFolder $folder The shared folder to update
     *
     * @throws \Exception
     */
    public static function updateSharedFolder(SharedFolder $folder): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $newEntry = $oldEntry = self::getSharedFolderEntry($ldap, $folder->email, $dn);

        if (empty($oldEntry)) {
            self::throwException(
                $ldap,
                "Failed to update shared folder {$folder->id} in LDAP (folder not found)"
            );
        }

        self::setSharedFolderAttributes($ldap, $folder, $newEntry);

        $result = $ldap->modify_entry($dn, $oldEntry, $newEntry);

        if (!is_array($result)) {
            self::throwException(
                $ldap,
                "Failed to update shared folder {$folder->id} in LDAP (" . __LINE__ . ")"
            );
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Update a user in LDAP.
     *
     * @param User $user the user account to update
     *
     * @throws \Exception
     */
    public static function updateUser(User $user): void
    {
        $config = self::getConfig();
        $ldap = self::initLDAP($config);

        $newEntry = $oldEntry = self::getUserEntry($ldap, $user->email, $dn, true);

        if (!$oldEntry) {
            self::throwException(
                $ldap,
                "Failed to update user {$user->email} in LDAP (user not found)"
            );
        }

        self::setUserAttributes($user, $newEntry);

        if (array_key_exists('objectclass', $newEntry)) {
            if (!in_array('inetuser', $newEntry['objectclass'])) {
                $newEntry['objectclass'][] = 'inetuser';
            }
        }

        if (array_key_exists('inetuserstatus', $newEntry)) {
            $newEntry['inetuserstatus'] = (string) $newEntry['inetuserstatus'];
        }

        if (array_key_exists('mailquota', $newEntry)) {
            $newEntry['mailquota'] = (string) $newEntry['mailquota'];
        }

        $result = $ldap->modify_entry($dn, $oldEntry, $newEntry);

        if (!is_array($result)) {
            self::throwException(
                $ldap,
                "Failed to update user {$user->email} in LDAP (" . __LINE__ . ")"
            );
        }

        if (empty(self::$ldap)) {
            $ldap->close();
        }
    }

    /**
     * Initialize connection to LDAP
     */
    private static function initLDAP(array $config)
    {
        if (self::$ldap) {
            return self::$ldap;
        }

        $ldap = new \Net_LDAP3($config);

        $connected = $ldap->connect();

        if (!$connected) {
            throw new \Exception("Failed to connect to LDAP");
        }

        $bound = $ldap->bind(
            \config("services.ldap.admin.bind_dn"),
            \config("services.ldap.admin.bind_pw")
        );

        if (!$bound) {
            throw new \Exception("Failed to bind to LDAP");
        }

        return $ldap;
    }

    /**
     * Set domain attributes
     */
    private static function setDomainAttributes(Domain $domain, array &$entry)
    {
        $entry['inetdomainstatus'] = $domain->status;
    }

    /**
     * Convert group member addresses in to valid entries.
     */
    private static function setGroupAttributes($ldap, Group $group, &$entry)
    {
        $settings = $group->getSettings(['sender_policy']);

        // Make sure the policy does not contain duplicates, they aren't allowed
        // by the ldap definition of kolabAllowSMTPSender attribute
        $sender_policy = json_decode($settings['sender_policy'] ?: '[]', true);
        $sender_policy = array_values(array_unique(array_map('strtolower', $sender_policy)));

        $entry['kolaballowsmtpsender'] = $sender_policy;
        $entry['cn'] = $group->name;
        $entry['uniquemember'] = [];

        $groupDomain = explode('@', $group->email, 2)[1];
        $domainBaseDN = self::baseDN($ldap, $groupDomain);
        $validMembers = [];
        $members = $group->getAddresses();

        foreach ($members as $member) {
            [$local, $domainName] = explode('@', $member);

            $memberDN = "uid={$member},ou=People,{$domainBaseDN}";
            $memberEntry = $ldap->get_entry($memberDN);

            // if the member is in the local domain but doesn't exist, drop it
            if ($domainName == $groupDomain && !$memberEntry) {
                continue;
            }

            // add the member if not in the local domain
            if (!$memberEntry) {
                $memberEntry = [
                    'cn' => $member,
                    'mail' => $member,
                    'objectclass' => [
                        'top',
                        'inetorgperson',
                        'organizationalperson',
                        'person',
                    ],
                    'sn' => 'unknown',
                ];

                $ldap->add_entry($memberDN, $memberEntry);
            }

            $entry['uniquemember'][] = $memberDN;
            $validMembers[] = $member;
        }

        // Update members in sql (some might have been removed),
        // skip model events to not invoke another update job
        if ($members !== $validMembers) {
            $group->setAddresses($validMembers, true);
        }
    }

    /**
     * Set common resource attributes
     */
    private static function setResourceAttributes($ldap, Resource $resource, &$entry)
    {
        $entry['cn'] = $resource->name;
        $entry['owner'] = null;
        $entry['kolabinvitationpolicy'] = null;
        $entry['acl'] = [];

        $settings = $resource->getSettings(['invitation_policy', 'folder']);

        $entry['kolabtargetfolder'] = $settings['folder'] ?? '';

        // Here's how Wallace's resources module works:
        // - if policy is ACT_MANUAL and owner mail specified: a tentative response is sent, event saved,
        //   and mail sent to the owner to accept/decline the request.
        // - if policy is ACT_ACCEPT_AND_NOTIFY and owner mail specified: an accept response is sent,
        //   event saved, and notification (not confirmation) mail sent to the owner.
        // - if there's no owner (policy irrelevant): an accept response is sent, event saved.
        // - if policy is ACT_REJECT: a decline response is sent
        // - note that the notification email is being send if COND_NOTIFY policy is set or saving failed.
        // - all above assume there's no conflict, if there's a conflict the decline response is sent automatically
        //   (notification is sent if policy = ACT_ACCEPT_AND_NOTIFY).
        // - the only supported policies are: 'ACT_MANUAL', 'ACT_ACCEPT' (defined but not used anywhere),
        //   'ACT_REJECT', 'ACT_ACCEPT_AND_NOTIFY'.

        // For now we ignore the notifications feature

        if (!empty($settings['invitation_policy'])) {
            if ($settings['invitation_policy'] === 'accept') {
                $entry['kolabinvitationpolicy'] = 'ACT_ACCEPT';
            } elseif ($settings['invitation_policy'] === 'reject') {
                $entry['kolabinvitationpolicy'] = 'ACT_REJECT';
            } elseif (preg_match('/^manual:(\S+@\S+)$/', $settings['invitation_policy'], $m)) {
                if (self::getUserEntry($ldap, $m[1], $userDN)) {
                    $entry['owner'] = $userDN;
                    $entry['acl'] = [$m[1] . ', full'];
                    $entry['kolabinvitationpolicy'] = 'ACT_MANUAL';
                } else {
                    $entry['kolabinvitationpolicy'] = 'ACT_ACCEPT';
                }
            }
        }

        $entry['acl'] = Utils::ensureAclPostPermission($entry['acl']);
    }

    /**
     * Set common shared folder attributes
     */
    private static function setSharedFolderAttributes($ldap, SharedFolder $folder, &$entry)
    {
        $settings = $folder->getSettings(['acl', 'folder']);

        $acl = !empty($settings['acl']) ? json_decode($settings['acl'], true) : [];

        $entry['cn'] = $folder->name;
        $entry['kolabfoldertype'] = $folder->type;
        $entry['kolabtargetfolder'] = $settings['folder'] ?? '';
        $entry['acl'] = Utils::ensureAclPostPermission($acl);
        $entry['alias'] = $folder->aliases()->pluck('alias')->all();
    }

    /**
     * Set common user attributes
     */
    private static function setUserAttributes(User $user, array &$entry)
    {
        $isDegraded = $user->isDegraded(true);
        $settings = $user->getSettings(['first_name', 'last_name', 'organization']);

        $firstName = $settings['first_name'];
        $lastName = $settings['last_name'];
        $cn = "unknown";
        $displayname = "";

        if ($firstName) {
            if ($lastName) {
                $cn = "{$firstName} {$lastName}";
                $displayname = "{$lastName}, {$firstName}";
            } else {
                $lastName = "unknown";
                $cn = "{$firstName}";
                $displayname = "{$firstName}";
            }
        } else {
            $firstName = "";
            if ($lastName) {
                $cn = "{$lastName}";
                $displayname = "{$lastName}";
            } else {
                $lastName = "unknown";
            }
        }

        $entry['cn'] = $cn;
        $entry['displayname'] = $displayname;
        $entry['givenname'] = $firstName;
        $entry['sn'] = $lastName;
        $entry['userpassword'] = $user->password_ldap;
        $entry['inetuserstatus'] = $user->status;
        $entry['o'] = $settings['organization'];
        $entry['mailquota'] = 0;
        $entry['alias'] = $user->aliases()->pluck('alias')->all();

        $roles = [];

        foreach ($user->entitlements as $entitlement) {
            switch ($entitlement->sku->title) {
                case "mailbox":
                    break;
                case "storage":
                    $entry['mailquota'] += 1048576;
                    break;
                default:
                    $roles[] = $entitlement->sku->title;
                    break;
            }
        }

        $hostedRootDN = \config('services.ldap.hosted.root_dn');

        $entry['nsroledn'] = [];

        if (in_array("2fa", $roles)) {
            $entry['nsroledn'][] = "cn=2fa-user,{$hostedRootDN}";
        }

        if ($isDegraded) {
            $entry['nsroledn'][] = "cn=degraded-user,{$hostedRootDN}";
            $entry['mailquota'] = \config('app.storage.min_qty') * 1048576;
        } else {
            if (in_array("activesync", $roles)) {
                $entry['nsroledn'][] = "cn=activesync-user,{$hostedRootDN}";
            }

            if (!in_array("groupware", $roles)) {
                $entry['nsroledn'][] = "cn=imap-user,{$hostedRootDN}";
            }
        }
    }

    /**
     * Get LDAP configuration for specified access level
     */
    private static function getConfig()
    {
        $config = [
            'domain_base_dn' => \config('services.ldap.domain_base_dn'),
            'domain_filter' => \config('services.ldap.domain_filter'),
            'domain_name_attribute' => \config('services.ldap.domain_name_attribute'),
            'hosts' => \config('services.ldap.hosts'),
            'sort' => false,
            'vlv' => false,
            'log_hook' => 'App\Backends\LDAP::logHook',
        ];

        return $config;
    }

    /**
     * Get group entry from LDAP.
     *
     * @param \Net_LDAP3 $ldap  Ldap connection
     * @param string     $email Group email (mail)
     * @param string     $dn    Reference to group DN
     *
     * @return array|null Group entry, NULL if not found
     */
    private static function getGroupEntry($ldap, $email, &$dn = null)
    {
        $domainName = explode('@', $email, 2)[1];
        $base_dn = self::baseDN($ldap, $domainName, 'Groups');

        $attrs = ['dn', 'cn', 'mail', 'uniquemember', 'objectclass', 'kolaballowsmtpsender'];

        // For groups we're using search() instead of get_entry() because
        // a group name is not constant, so e.g. on update we might have
        // the new name, but not the old one. Email address is constant.
        return self::searchEntry($ldap, $base_dn, "(mail={$email})", $attrs, $dn);
    }

    /**
     * Get a resource entry from LDAP.
     *
     * @param \Net_LDAP3 $ldap  Ldap connection
     * @param string     $email Resource email (mail)
     * @param string     $dn    Reference to the resource DN
     *
     * @return array|null Resource entry, NULL if not found
     */
    private static function getResourceEntry($ldap, $email, &$dn = null)
    {
        $domainName = explode('@', $email, 2)[1];
        $base_dn = self::baseDN($ldap, $domainName, 'Resources');

        $attrs = ['dn', 'cn', 'mail', 'objectclass', 'kolabtargetfolder',
            'kolabfoldertype', 'kolabinvitationpolicy', 'owner', 'acl'];

        // For resources we're using search() instead of get_entry() because
        // a resource name is not constant, so e.g. on update we might have
        // the new name, but not the old one. Email address is constant.
        return self::searchEntry($ldap, $base_dn, "(mail={$email})", $attrs, $dn);
    }

    /**
     * Get a shared folder entry from LDAP.
     *
     * @param \Net_LDAP3 $ldap  Ldap connection
     * @param string     $email Resource email (mail)
     * @param string     $dn    Reference to the shared folder DN
     *
     * @return array|null Shared folder entry, NULL if not found
     */
    private static function getSharedFolderEntry($ldap, $email, &$dn = null)
    {
        $domainName = explode('@', $email, 2)[1];
        $base_dn = self::baseDN($ldap, $domainName, 'Shared Folders');

        $attrs = ['dn', 'cn', 'mail', 'objectclass', 'kolabtargetfolder', 'kolabfoldertype', 'acl', 'alias'];

        // For shared folders we're using search() instead of get_entry() because
        // a folder name is not constant, so e.g. on update we might have
        // the new name, but not the old one. Email address is constant.
        return self::searchEntry($ldap, $base_dn, "(mail={$email})", $attrs, $dn);
    }

    /**
     * Get user entry from LDAP.
     *
     * @param \Net_LDAP3 $ldap  Ldap connection
     * @param string     $email User email (uid)
     * @param string     $dn    Reference to user DN
     * @param bool       $full  Get extra attributes, e.g. nsroledn
     *
     * @return array|null User entry, NULL if not found
     */
    private static function getUserEntry($ldap, $email, &$dn, $full = false)
    {
        $domainName = explode('@', $email, 2)[1];

        $dn = "uid={$email}," . self::baseDN($ldap, $domainName, 'People');

        $entry = $ldap->get_entry($dn);

        if ($entry && $full) {
            if (!array_key_exists('nsroledn', $entry)) {
                $roles = $ldap->get_entry_attributes($dn, ['nsroledn']);
                if (!empty($roles)) {
                    $entry['nsroledn'] = (array) $roles['nsroledn'];
                }
            }
        }

        return $entry ?: null;
    }

    /**
     * Logging callback
     */
    public static function logHook($level, $msg): void
    {
        if (
            (
                $level == \LOG_INFO
                || $level == \LOG_DEBUG
                || $level == \LOG_NOTICE
            )
            && !\config('app.debug')
        ) {
            return;
        }

        switch ($level) {
            case \LOG_CRIT:
                $function = 'critical';
                break;
            case \LOG_EMERG:
                $function = 'emergency';
                break;
            case \LOG_ERR:
                $function = 'error';
                break;
            case \LOG_ALERT:
                $function = 'alert';
                break;
            case \LOG_WARNING:
                $function = 'warning';
                break;
            case \LOG_INFO:
                $function = 'info';
                break;
            case \LOG_DEBUG:
                $function = 'debug';
                break;
            case \LOG_NOTICE:
                $function = 'notice';
                break;
            default:
                $function = 'info';
        }

        if (is_array($msg)) {
            $msg = implode("\n", $msg);
        }

        $msg = '[LDAP] ' . $msg;

        \Log::{$function}($msg);
    }

    /**
     * A wrapper for Net_LDAP3::add_entry() with error handler
     *
     * @param \Net_LDAP3 $ldap     Ldap connection
     * @param string     $dn       Entry DN
     * @param array      $entry    Entry attributes
     * @param ?string    $errorMsg A message to throw as an exception on error
     *
     * @throws \Exception
     */
    private static function addEntry($ldap, string $dn, array $entry, $errorMsg = null)
    {
        // try/catch because Laravel converts warnings into exceptions
        // and we want more human-friendly error message than that
        try {
            $result = $ldap->add_entry($dn, $entry);
        } catch (\Exception $e) {
            $result = false;
        }

        if (!$result) {
            if (!$errorMsg) {
                $errorMsg = "LDAP Error (" . __LINE__ . ")";
            }

            if (isset($e)) {
                $errorMsg .= ": " . $e->getMessage();
            }

            self::throwException($ldap, $errorMsg);
        }
    }

    /**
     * Find a single entry in LDAP by using search.
     *
     * @param \Net_LDAP3 $ldap    Ldap connection
     * @param string     $base_dn Base DN
     * @param string     $filter  Search filter
     * @param array      $attrs   Result attributes
     * @param string     $dn      Reference to a DN of the found entry
     *
     * @return array|null LDAP entry, NULL if not found
     */
    private static function searchEntry($ldap, $base_dn, $filter, $attrs, &$dn = null)
    {
        $result = $ldap->search($base_dn, $filter, 'sub', $attrs);

        if ($result && $result->count() == 1) {
            $entries = $result->entries(true);
            $dn = key($entries);
            $entry = $entries[$dn];
            $entry['dn'] = $dn;

            return $entry;
        }

        return null;
    }

    /**
     * Throw exception and close the connection when needed
     *
     * @param \Net_LDAP3 $ldap    Ldap connection
     * @param string     $message Exception message
     *
     * @throws \Exception
     */
    private static function throwException($ldap, string $message): void
    {
        if (empty(self::$ldap)) {
            $ldap->close();
        }

        throw new \Exception($message);
    }

    /**
     * Create a base DN string for a specified object.
     * Note: It makes sense with an existing domain only.
     *
     * @param \Net_LDAP3 $ldap       Ldap connection
     * @param string     $domainName Domain namespace
     * @param ?string    $ouName     Optional name of the sub-tree (OU)
     *
     * @return string Full base DN
     */
    private static function baseDN($ldap, string $domainName, ?string $ouName = null): string
    {
        $dn = $ldap->domain_root_dn($domainName);

        if ($ouName) {
            $dn = "ou={$ouName},{$dn}";
        }

        return $dn;
    }
}
