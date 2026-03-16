<?php

namespace App\Backends;

use App\Auth\Utils;
use App\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class DAV
{
    public const TYPE_VEVENT = 'VEVENT';
    public const TYPE_VTODO = 'VTODO';
    public const TYPE_VCARD = 'VCARD';
    public const TYPE_NOTIFICATION = 'NOTIFICATION';
    public const TYPE_NOTE = 'NOTE';

    public const NAMESPACES = [
        self::TYPE_VEVENT => 'urn:ietf:params:xml:ns:caldav',
        self::TYPE_VTODO => 'urn:ietf:params:xml:ns:caldav',
        self::TYPE_VCARD => 'urn:ietf:params:xml:ns:carddav',
        self::TYPE_NOTE => 'Kolab:',
    ];

    protected $url;
    protected $user;
    protected $password;
    protected $responseHeaders = [];
    protected $homes;

    /**
     * Get object instance for user/password/location
     *
     * @param string  $user     Username
     * @param string  $password Password
     * @param ?string $url      Server location (defaults to services.dav.uri option value)
     *
     * @return DAV DAV client instance
     */
    public static function getInstance($user, $password, $url = null): self
    {
        $dav = new self();
        $dav->setCredentials($user, $password);
        $dav->setUrl($url ?: \config('services.dav.uri'));

        return $dav;
    }

    /**
     * Set DAV server location
     *
     * @param string $url Server location (URL)
     */
    public function setUrl($url): void
    {
        $this->url = $url;
    }

    /**
     * Set user name and password
     *
     * @param string $user     Username
     * @param string $password Password
     */
    public function setCredentials($user, $password): void
    {
        $this->user = $user;
        $this->password = $password;
    }

    /**
     * Return collection resource type for specified object type
     */
    public static function collectionType($type): ?string
    {
        return match ($type) {
            self::TYPE_VCARD => 'addressbook',
            self::TYPE_NOTE => 'notebook',
            self::TYPE_VEVENT => 'calendar',
            self::TYPE_VTODO => 'calendar',
            default => null,
        };
    }

    /**
     * Discover DAV home (root) collection of a specified type.
     *
     * @return array|false Home locations or False on error
     */
    public function discover()
    {
        if (is_array($this->homes)) {
            return $this->homes;
        }

        $path = parse_url($this->url, \PHP_URL_PATH);

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:">'
                . '<d:prop>'
                    . '<d:current-user-principal />'
                . '</d:prop>'
            . '</d:propfind>';

        // Note: Cyrus CardDAV service requires Depth:1 (CalDAV works without it)
        $response = $this->request('', 'PROPFIND', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            \Log::error("Failed to get current-user-principal from the DAV server.");
            return false;
        }

        $elements = $response->getElementsByTagName('response');
        $principal_href = '';

        foreach ($elements as $element) {
            foreach ($element->getElementsByTagName('current-user-principal') as $prop) {
                $principal_href = $prop->nodeValue;
                break;
            }
        }

        if ($path && str_starts_with($principal_href, $path)) {
            $principal_href = substr($principal_href, strlen($path));
        }

        $ns = [
            'xmlns:d="DAV:"',
            'xmlns:cal="urn:ietf:params:xml:ns:caldav"',
            'xmlns:card="urn:ietf:params:xml:ns:carddav"',
        ];

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind ' . implode(' ', $ns) . '>'
                . '<d:prop>'
                    . '<cal:calendar-home-set/>'
                    . '<card:addressbook-home-set/>'
                    . '<d:notification-URL/>'
                . '</d:prop>'
            . '</d:propfind>';

        $response = $this->request($principal_href, 'PROPFIND', $body);

        if (empty($response)) {
            \Log::error("Failed to get home collections from the DAV server.");
            return false;
        }

        $elements = $response->getElementsByTagName('response');
        $homes = [];

        if ($element = $response->getElementsByTagName('response')->item(0)) {
            if ($prop = $element->getElementsByTagName('prop')->item(0)) {
                foreach ($prop->childNodes as $home) {
                    if ($home->firstChild && $home->firstChild->localName == 'href') {
                        $href = $home->firstChild->nodeValue;

                        if ($path && str_starts_with($href, $path)) {
                            $href = substr($href, strlen($path));
                        }

                        $homes[$home->localName] = $href;
                    }
                }
            }
        }

        return $this->homes = $homes;
    }

    /**
     * Get user home folder of specified type
     *
     * @param string $type Home type or component name
     *
     * @return string|null Folder location href
     */
    public function getHome($type)
    {
        if ($type == self::TYPE_NOTE) {
            return 'dav/files/user/' . $this->user;
        }

        $options = [
            self::TYPE_VEVENT => 'calendar-home-set',
            self::TYPE_VTODO => 'calendar-home-set',
            self::TYPE_VCARD => 'addressbook-home-set',
            self::TYPE_NOTIFICATION => 'notification-URL',
        ];

        $homes = $this->discover();

        if (is_array($homes) && isset($options[$type])) {
            return $homes[$options[$type]] ?? null;
        }

        return null;
    }

    /**
     * Check if we can connect to the DAV server
     *
     * @return bool True on success, False otherwise
     */
    public static function healthcheck($username, $password): bool
    {
        // TODO: healthcheck for the built-in WebDAV server?

        $homes = self::getInstance($username, $password)->discover();
        return !empty($homes);
    }

    /**
     * Get list of folders of specified type.
     *
     * @param string $component Component to filter by (VEVENT, VTODO, VCARD)
     *
     * @return false|array<DAV\Folder> List of folders' metadata or False on error
     */
    public function listFolders(string $component)
    {
        $root_href = $this->getHome($component);

        if ($root_href === null) {
            return false;
        }

        // Note: Cyrus CardDAV service requires Depth:1 (CalDAV works without it)
        $headers = ['Depth' => 1, 'Prefer' => 'return-minimal'];

        $response = $this->request($root_href, 'PROPFIND', DAV\Folder::propfindXML(), $headers);

        if (empty($response)) {
            \Log::error("Failed to get folders list from the DAV server.");
            return false;
        }

        $folders = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            $folder = DAV\Folder::fromDomElement($element);

            // Note: Addressbooks and Notebooks don't have components specified
            if (
                ($component == self::TYPE_VCARD && in_array('addressbook', $folder->types))
                || ($component == self::TYPE_NOTE && in_array('notebook', $folder->types))
                || in_array($component, $folder->components)
            ) {
                $folders[] = $folder;
            }
        }

        return $folders;
    }

    /**
     * Create a DAV object in a folder
     *
     * @param DAV\CommonObject $object Object
     *
     * @return false|DAV\CommonObject Object on success, False on error
     */
    public function create(DAV\CommonObject $object)
    {
        $headers = ['Content-Type' => $object->contentType];

        $content = (string) $object;

        if (!strlen($content)) {
            throw new \Exception("Cannot PUT an empty DAV object");
        }

        $response = $this->request($object->href, 'PUT', $content, $headers);

        if ($response !== false) {
            if (!empty($this->responseHeaders['ETag'])) {
                $etag = $this->responseHeaders['ETag'][0];
                if (preg_match('|^".*"$|', $etag)) {
                    $etag = substr($etag, 1, -1);
                }

                $object->etag = $etag;
            }

            return $object;
        }

        return false;
    }

    /**
     * Update a DAV object in a folder
     *
     * @param DAV\CommonObject $object Object
     *
     * @return false|DAV\CommonObject Object on success, False on error
     */
    public function update(DAV\CommonObject $object)
    {
        return $this->create($object);
    }

    /**
     * Delete a DAV object from a folder
     *
     * @param string $location Object location
     *
     * @return bool True on success, False on error
     */
    public function delete(string $location)
    {
        $response = $this->request($location, 'DELETE', '', ['Depth' => 1, 'Prefer' => 'return-minimal']);

        return $response !== false;
    }

    /**
     * Create a DAV folder (collection)
     *
     * @param DAV\Folder $folder Folder object
     *
     * @return bool True on success, False on error
     */
    public function folderCreate(DAV\Folder $folder)
    {
        $response = $this->request($folder->href, 'MKCOL', $folder->toXML('mkcol'));

        return $response !== false;
    }

    /**
     * Delete a DAV folder (collection)
     *
     * @param string $location Folder location
     *
     * @return bool True on success, False on error
     */
    public function folderDelete($location)
    {
        $response = $this->request($location, 'DELETE');

        return $response !== false;
    }

    /**
     * Get all properties of a folder.
     *
     * @param string $location Object location
     *
     * @return false|DAV\Folder Folder metadata or False on error
     */
    public function folderInfo(string $location)
    {
        $body = DAV\Folder::propfindXML();

        // Note: Cyrus CardDAV service requires Depth:1 (CalDAV works without it)
        $response = $this->request($location, 'PROPFIND', $body, ['Depth' => 0, 'Prefer' => 'return-minimal']);

        if (!empty($response) && ($element = $response->getElementsByTagName('response')->item(0))) {
            return DAV\Folder::fromDomElement($element);
        }

        return false;
    }

    /**
     * Update a DAV folder (collection)
     *
     * @param DAV\Folder $folder Folder object
     *
     * @return bool True on success, False on error
     */
    public function folderUpdate(DAV\Folder $folder)
    {
        // Note: Changing resourcetype property is forbidden (at least by Cyrus)

        $response = $this->request($folder->href, 'PROPPATCH', $folder->toXML('propertyupdate'));

        return $response !== false;
    }

    /**
     * Get a single DAV notification
     *
     * @param string $location Notification href
     *
     * @return ?DAV\Notification Notification data on success
     */
    public function getNotification($location)
    {
        $response = $this->request($location, 'GET');

        if ($response && ($element = $response->getElementsByTagName('notification')->item(0))) {
            return DAV\Notification::fromDomElement($element, $location);
        }

        return null;
    }

    /**
     * Initialize default DAV folders (collections)
     *
     * @param User $user User object
     *
     * @throws \Exception
     */
    public static function initDefaultFolders(User $user): void
    {
        if (!\config('services.dav.uri')) {
            return;
        }

        $folders = \config('services.dav.default_folders');
        if (!count($folders)) {
            return;
        }

        $dav = self::getClientForUser($user);

        foreach ($folders as $props) {
            $folder = new DAV\Folder();
            $folder->href = $props['type'] . 's/user/' . $user->email . '/' . $props['path'];
            $folder->types = ['collection', $props['type']];
            $folder->name = $props['displayname'] ?? '';
            $folder->components = $props['components'] ?? [];

            $existing = null;
            try {
                $existing = $dav->folderInfo($folder->href);
            } catch (RequestException $e) {
                // Cyrus DAV returns 503 Service Unavailable on a non-existing location (?)
                if ($e->getCode() != 503 && $e->getCode() != 404) {
                    throw $e;
                }
            }

            // folder already exists? check the properties and update if needed
            if ($existing) {
                if ($existing->name != $folder->name || $existing->components != $folder->components) {
                    if (!$dav->folderUpdate($folder)) {
                        throw new \Exception("Failed to update DAV folder {$folder->href}");
                    }
                }
            } elseif (!$dav->folderCreate($folder)) {
                throw new \Exception("Failed to create DAV folder {$folder->href}");
            }
        }
    }

    /**
     * Accept/Deny a share invitation (draft-pot-webdav-resource-sharing)
     *
     * @param string          $location Notification location
     * @param DAV\InviteReply $reply    Invite reply
     *
     * @return bool True on success, False on error
     */
    public function inviteReply($location, $reply): bool
    {
        $response = $this->request($location, 'POST', $reply, ['Content-Type' => $reply->contentType]);

        return $response !== false;
    }

    /**
     * Fetch DAV notes
     *
     * @param string $location Folder location
     *
     * @return array<DAV\Note> Notes objects
     */
    public function listNotes($location): array
    {
        // FIXME: As far as I can see there's no other way to get only the notes we're interested in

        $body = DAV\Note::propfindXML();

        $response = $this->request($location, 'PROPFIND', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return [];
        }

        $notes = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            $note = $this->objectFromElement($element, self::TYPE_NOTE);
            if ($note->mimetype == 'text/html') {
                // skip non-note elements (e.g. the parent folder)
                continue;
            }

            $notes[] = $note;
        }

        return $notes;
    }

    /**
     * Fetch DAV notifications
     *
     * @param array $types Notification types to return
     *
     * @return array<DAV\Notification> Notification objects
     */
    public function listNotifications(array $types = []): array
    {
        $root_href = $this->getHome(self::TYPE_NOTIFICATION);

        if ($root_href === null) {
            return [];
        }

        // FIXME: As far as I can see there's no other way to get only the notifications we're interested in

        $body = DAV\Notification::propfindXML();

        $response = $this->request($root_href, 'PROPFIND', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return [];
        }

        $objects = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            $type = $element->getElementsByTagName('notificationtype')->item(0);
            if (!$type || !$type->firstChild) {
                // skip non-notification elements (e.g. a parent folder)
                continue;
            }

            $type = $type->firstChild->localName;

            if (empty($types) || in_array($type, $types)) {
                if ($href = $element->getElementsByTagName('href')->item(0)) {
                    if ($n = $this->getNotification($href->nodeValue)) {
                        $objects[] = $n;
                    }
                }
            }
        }

        return $objects;
    }

    /**
     * Check server options (and authentication)
     *
     * @return false|array DAV capabilities on success, False on error
     */
    public function options()
    {
        $response = $this->request('', 'OPTIONS');

        if ($response !== false) {
            return preg_split('/,\s+/', implode(',', $this->responseHeaders['DAV'] ?? []));
        }

        return false;
    }

    /**
     * Patch a DAV object in a folder
     *
     * @param DAV\CommonObject $object Object
     */
    public function propPatch(DAV\CommonObject $object): bool
    {
        $xml = $object->toXML();

        if (!strlen($xml)) {
            // This type of object is not updateable via PROPPATCH
            return true;
        }

        $response = $this->request($object->href, 'PROPPATCH', $xml);

        return $response !== false;
    }

    /**
     * Search DAV objects in a folder.
     *
     * @param string     $location     Folder location
     * @param DAV\Search $search       Search request parameters
     * @param callable   $callback     A callback to execute on every item
     * @param bool       $opaque       Return objects as instances of DAV\Opaque
     * @param array      $extraHeaders Extra headers for the REPORT request
     *
     * @return false|array List of objects on success, False on error
     */
    public function search(string $location, DAV\Search $search, $callback = null, $opaque = false, $extraHeaders = [])
    {
        $headers = array_merge(
            ['Depth' => $search->depth, 'Prefer' => 'return-minimal'],
            $extraHeaders
        );

        $response = $this->request($location, 'REPORT', $search, $headers);

        if (empty($response)) {
            \Log::error("Failed to get objects from the DAV server.");
            return false;
        }

        $objects = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            if ($opaque) {
                $object = DAV\Opaque::fromDomElement($element);
            } else {
                $object = $this->objectFromElement($element, $search->component);
            }

            if ($callback) {
                $object = $callback($object);
            }

            if ($object) {
                if (is_array($object)) {
                    $objects[$object[0]] = $object[1];
                } else {
                    $objects[] = $object;
                }
            }
        }

        return $objects;
    }

    /**
     * Share default DAV folders with specified user (delegatee)
     *
     * @param User  $user The user
     * @param User  $to   The delegated user
     * @param array $acl  ACL Permissions per folder type
     *
     * @throws \Exception
     */
    public static function shareDefaultFolders(User $user, User $to, array $acl): void
    {
        if (!\config('services.dav.uri')) {
            return;
        }

        $folders = [];
        foreach (\config('services.dav.default_folders') as $folder) {
            if ($folder['type'] == 'addressbook') {
                $type = 'contact';
            } elseif (in_array('VTODO', $folder['components'] ?? [])) {
                $type = 'task';
            } elseif (in_array('VEVENT', $folder['components'] ?? [])) {
                $type = 'event';
            } else {
                continue;
            }

            if (!empty($acl[$type])) {
                $folders[] = [
                    'href' => $folder['type'] . 's/user/' . $user->email . '/' . $folder['path'],
                    'acl' => $acl[$type] == 'read-write'
                        ? DAV\ShareResource::ACCESS_READ_WRITE : DAV\ShareResource::ACCESS_READ,
                ];
            }
        }

        if (empty($folders)) {
            return;
        }

        $dav = self::getClientForUser($user);

        // Create sharing invitations
        foreach ($folders as $folder) {
            $share_resource = new DAV\ShareResource();
            $share_resource->href = $folder['href'];
            $share_resource->sharees = [$dav->principalLocation($to->email) => $folder['acl']];

            try {
                if (!$dav->shareResource($share_resource)) {
                    throw new \Exception("Failed to share DAV folder {$folder['href']}");
                }
            } catch (RequestException $e) {
                // Silently ignore non-existing folders
                if ($e->getCode() != 404) {
                    throw $e;
                }
            }
        }

        // Accept sharing invitations
        $dav = self::getClientForUser($to);

        // FIXME/TODO: It would be nice to be able to fetch only notifications that are:
        // - created by the $user
        // - are invite notification with invite-noresponse, or only these created in last minute
        // Right now we'll do this filtering here
        foreach ($dav->listNotifications([DAV\Notification::NOTIFICATION_SHARE_INVITE]) as $n) {
            if (
                $n->status == $n::INVITE_NORESPONSE
                && strpos((string) $n->principal, "/user/{$user->email}")
            ) {
                $reply = new DAV\InviteReply();
                $reply->type = $reply::INVITE_ACCEPTED;

                if (!$dav->inviteReply($n->href, $reply)) {
                    throw new \Exception("Failed to accept DAV share invitation {$n->href}");
                }
            }
        }
    }

    /**
     * Set folder sharing invites (draft-pot-webdav-resource-sharing)
     *
     * @param DAV\ShareResource $resource Share resource
     *
     * @return bool True on success, False on error
     */
    public function shareResource(DAV\ShareResource $resource): bool
    {
        $response = $this->request($resource->href, 'POST', $resource, ['Content-Type' => $resource->contentType]);

        return $response !== false;
    }

    /**
     * Unshare folders.
     *
     * @param User   $user  Folders' owner
     * @param string $email Delegated user
     *
     * @throws \Exception
     */
    public static function unshareFolders(User $user, string $email): void
    {
        $dav = self::getClientForUser($user);

        foreach ([self::TYPE_VEVENT, self::TYPE_VTODO, self::TYPE_VCARD] as $type) {
            foreach ($dav->listFolders($type) as $folder) {
                if ($folder->owner === $user->email && isset($folder->invites["mailto:{$email}"])) {
                    $share_resource = new DAV\ShareResource();
                    $share_resource->href = $folder->href;
                    $share_resource->sharees = [$dav->principalLocation($email) => $share_resource::ACCESS_NONE];
                    if (!$dav->shareResource($share_resource)) {
                        throw new \Exception("Failed to unshare DAV folder {$folder->href}");
                    }
                }
            }
        }
    }

    /**
     * Unsubscribe folders shared by other users.
     *
     * @param User   $user  Account owner
     * @param string $email Other user email address
     *
     * @throws \Exception
     */
    public static function unsubscribeSharedFolders(User $user, string $email): void
    {
        $dav = self::getClientForUser($user);

        foreach ([self::TYPE_VEVENT, self::TYPE_VTODO, self::TYPE_VCARD] as $type) {
            foreach ($dav->listFolders($type) as $folder) {
                if ($folder->owner === $email && $user->email != $email) {
                    $response = $dav->request($folder->href, 'DELETE');
                    if ($response === false) {
                        throw new \Exception("Failed to unsubscribe DAV folder {$folder->href}");
                    }
                }
            }
        }
    }

    /**
     * Fetch DAV objects data from a folder
     *
     * @param string $location  Folder location
     * @param string $component Object type (VEVENT, VTODO, VCARD)
     * @param array  $hrefs     List of objects' locations to fetch
     *
     * @return false|array Objects metadata on success, False on error
     */
    public function getObjects(string $location, string $component, array $hrefs = [])
    {
        if (empty($hrefs)) {
            return [];
        }

        $search = new DAV\Search($component, true, [], true);
        $search->properties = ['d:getetag'];
        $search->hrefs = $hrefs;

        $headers = ['Depth' => $search->depth, 'Prefer' => 'return-minimal'];

        $response = $this->request($location, 'REPORT', $search, $headers);

        if (empty($response)) {
            \Log::error("Failed to get objects from the DAV server.");
            return false;
        }

        $objects = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            $objects[] = $this->objectFromElement($element, $component);
        }

        return $objects;
    }

    /**
     * Create DAV client instance for a user (using generated auth token as password)
     */
    protected static function getClientForUser(User $user): self
    {
        // Cyrus DAV does not support proxy authorization via DAV. Even though it has
        // the Authorize-As header, it is used only for cummunication with Murder backends.
        // We use a one-time token instead. It's valid for 10 seconds, assume it's enough time.
        $password = Utils::tokenCreate((string) $user->id);

        if ($password === null) {
            throw new \Exception("Failed to create an authentication token for DAV");
        }

        return self::getInstance($user->email, $password);
    }

    /**
     * Parse XML content
     */
    protected function parseXML($xml)
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');

        if (str_starts_with($xml, '<?xml')) {
            if (!$doc->loadXML($xml, \LIBXML_PARSEHUGE)) {
                throw new \Exception("Failed to parse XML");
            }

            $doc->formatOutput = true;
        }

        return $doc;
    }

    /**
     * Parse request/response body for debug purposes
     */
    protected function debugBody($body, $headers)
    {
        $head = '';

        foreach ($headers as $header_name => $header_value) {
            if (is_array($header_value)) {
                $header_value = implode("\n\t", $header_value);
            }

            $head .= "{$header_name}: {$header_value}\n";
        }

        if ($body instanceof DAV\CommonObject) {
            $body = (string) $body;
        }

        if (str_starts_with($body, '<?xml')) {
            $doc = new \DOMDocument('1.0', 'UTF-8');

            $doc->formatOutput = true;
            $doc->preserveWhiteSpace = false;

            libxml_use_internal_errors(true);

            if (!$doc->loadXML($body, \LIBXML_PARSEHUGE)) {
                throw new \Exception("Failed to parse XML");
            }

            foreach (libxml_get_errors() as $error) {
                \Log::error(trim($error->message) . " on line " . $error->line . \PHP_EOL);
            }
            libxml_clear_errors();

            $body = $doc->saveXML();
        }

        return $head . (is_string($body) && strlen($body) > 0 ? "\n{$body}" : '');
    }

    /**
     * Create DAV\CommonObject from a DOMElement
     */
    protected function objectFromElement($element, $component)
    {
        switch ($component) {
            case self::TYPE_VEVENT:
                $object = DAV\Vevent::fromDomElement($element);
                break;
            case self::TYPE_VTODO:
                $object = DAV\Vtodo::fromDomElement($element);
                break;
            case self::TYPE_VCARD:
                $object = DAV\Vcard::fromDomElement($element);
                break;
            case self::TYPE_NOTE:
                $object = DAV\Note::fromDomElement($element);
                break;
            default:
                throw new \Exception("Unknown component: {$component}");
        }

        return $object;
    }

    /**
     * Convert user email address into a principal location (href)
     *
     * @param string $email Email address
     */
    public function principalLocation(string $email): string
    {
        // TODO: This might need to be configurable or discovered somehow,
        // maybe get it from current-user-principal property that we read in discover()
        $path = '/principals/user/';
        if ($host_path = parse_url($this->url, \PHP_URL_PATH)) {
            $path = '/' . trim($host_path, '/') . $path;
        }

        return $path . $email;
    }

    /**
     * Execute HTTP request to a DAV server
     */
    public function request($path, $method, $body = '', $headers = [])
    {
        $debug = \config('app.debug');
        $url = $this->url;

        $this->responseHeaders = [];

        // Remove the duplicate path prefix
        if ($path) {
            $rootPath = parse_url($url, \PHP_URL_PATH);
            $path = '/' . ltrim($path, '/');

            if ($rootPath && str_starts_with($path, $rootPath)) {
                $path = substr($path, strlen($rootPath));
                // If rootPath has a trailing slash we end up removing it.
                $path = '/' . ltrim($path, '/');
            }

            $url = rtrim($url, '/') . $path;
        }

        $client = Http::withOptions(['verify' => \config('services.dav.verify')]);
        $client = $client->timeout(300);

        if ($this->user) {
            $client = $client->withBasicAuth($this->user, $this->password);
        }

        if ($body) {
            if (!isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/xml; charset=utf-8';
            }

            $client->withBody($body, $headers['Content-Type']);
        }

        if (!empty($headers)) {
            $client->withHeaders($headers);
        }

        if ($debug) {
            $body = $this->debugBody($body, $headers);
            \Log::debug("C: {$method}: {$url}" . (strlen($body) > 0 ? "\n{$body}" : ''));
        }

        $response = $client->send($method, $url);

        $body = $response->body();
        $code = $response->status();

        if ($debug) {
            \Log::debug("S: [{$code}]\n" . $this->debugBody($body, $response->headers()));
        }

        // Throw an exception if a client or server error occurred...
        $response->throw();

        $this->responseHeaders = $response->headers();

        return $this->parseXML($body);
    }
}
