<?php

namespace App\DataMigrator\Driver;

use App\DataMigrator\Account;
use App\DataMigrator\Engine;
use App\DataMigrator\Interface\ExporterInterface;
use App\DataMigrator\Interface\Folder;
use App\DataMigrator\Interface\ImporterInterface;
use App\DataMigrator\Interface\Item;
use App\DataMigrator\Interface\ItemSet;
use App\DataMigrator\Queue;
use garethp\ews\API;
use garethp\ews\API\Type;
use Illuminate\Support\Facades\Http;

/**
 * Data migration from Exchange (EWS)
 */
class EWS implements ExporterInterface
{
    /** @var int Max number of items to migrate in one go */
    protected const CHUNK_SIZE = 20;

    /** @var API EWS API object */
    public $api;

    /** @var array Supported folder types */
    protected $folder_classes = [
        EWS\Appointment::FOLDER_TYPE,
        EWS\Contact::FOLDER_TYPE,
        EWS\Task::FOLDER_TYPE,
        EWS\Email::FOLDER_TYPE,
        // TODO: mail and sticky notes are exported as eml files.
        //       We could use imapsync to synchronize mail, but for notes
        //       the only option will be to convert them to Kolab format here
        //       and upload to Kolab via IMAP, I guess
        // EWS\Note::FOLDER_TYPE,
        // EWS\StickyNote::FOLDER_TYPE,
    ];

    /** @var array Interal folders to skip */
    protected $folder_exceptions = [
        'AllCategorizedItems',
        'AllContacts',
        'AllContactsExtended',
        'AllPersonMetadata',
        'AllTodoTasks',
        'Calendar Version Store',
        'Document Centric Conversations',
        'ExternalContacts',
        'Flagged Emails',
        'Folder Memberships',
        'GraphFilesAndWorkingSetSearchFolder',
        'MyContactsExtended',
        'Orion Notes',
        'Outbox',
        'PersonMetadata',
        'People I Know',
        'RelevantContacts',
        'SharedFilesSearchFolder',
        'Sharing',
        'SpoolsPresentSharedItemsSearchFolder',
        'SpoolsSearchFolder',
        'To-Do Search',
        'UserCuratedContacts',
        'XrmActivityStreamSearch',
        'XrmCompanySearch',
        'XrmDealSearch',
        'XrmSearch',
        'MS-OLK-AllCalendarItems',
        'MS-OLK-AllContactItems',
        'MS-OLK-AllMailItems',
        // TODO: These are different depending on a user locale and it's not possible
        // to switch to English other than changing the user locale in OWA/Exchange.
        'Calendar/United States holidays',
        'Favorites',
        'My Contacts',
        'Kalendarz/Polska — dni wolne od pracy', // pl
        'Ulubione', // pl
        'Moje kontakty', // pl
        'Aufgabensuche', // de
        'Postausgang', // de
    ];

    /** @var array Map of EWS folder types to Kolab types */
    protected $type_map = [
        EWS\Appointment::FOLDER_TYPE => Engine::TYPE_EVENT,
        EWS\Contact::FOLDER_TYPE => Engine::TYPE_CONTACT,
        EWS\Task::FOLDER_TYPE => Engine::TYPE_TASK,
        EWS\Email::FOLDER_TYPE => Engine::TYPE_MAIL,
    ];

    /** @var Account Account to operate on */
    protected $account;

    /** @var Engine Data migrator engine */
    protected $engine;

    /**
     * Object constructor
     */
    public function __construct(Account $account, Engine $engine)
    {
        $this->account = $account;
        $this->engine = $engine;
    }

    /**
     * Server autodiscovery
     */
    public static function autodiscover(string $user, string $password): ?string
    {
        // You should never run the Autodiscover more than once.
        // It can make between 1 and 5 calls before giving up, or before finding your server,
        // depending on how many different attempts it needs to make.

        // TODO: Autodiscovery may fail with an exception thrown. Handle this nicely.
        // TODO: Looks like this autodiscovery also does not work w/Basic Auth?

        $api = API\ExchangeAutodiscover::getAPI($user, $password);

        $server = $api->getClient()->getServer();
        $version = $api->getClient()->getVersion();

        return sprintf('ews://%s:%s@%s', urlencode($user), urlencode($password), $server);
    }

    /**
     * Authenticate to EWS (initialize the EWS client)
     */
    public function authenticate(): void
    {
        if (!empty($this->account->params['client_id'])) {
            $this->api = $this->authenticateWithOAuth2(
                $this->account->host,
                $this->account->username,
                $this->account->params['client_id'],
                $this->account->params['client_secret'],
                $this->account->params['tenant_id']
            );
        } else {
            // Note: This initializes the client, but not yet connects to the server
            // TODO: To know that the credentials work we'll have to do some API call.
            $this->api = $this->authenticateWithPassword(
                $this->account->host,
                $this->account->username,
                $this->account->password,
                $this->account->loginas
            );
        }
    }

    /**
     * Autodiscover the server and authenticate the user
     */
    protected function authenticateWithPassword(string $server, string $user, string $password, ?string $loginas = null)
    {
        // Note: Since 2023-01-01 EWS at Office365 requires OAuth2, no way back to basic auth.

        \Log::debug("[EWS] Using basic authentication on {$server}...");

        $options = [];

        if ($loginas) {
            $options['impersonation'] = $loginas;
        }

        $this->engine->setOption('ews', [
            'options' => $options,
            'server' => $server,
        ]);

        return API::withUsernameAndPassword($server, $user, $password, $this->apiOptions($options));
    }

    /**
     * Authenticate with a token (Office365)
     */
    protected function authenticateWithToken(string $server, string $user, string $token, $expires_at = null)
    {
        \Log::debug("[EWS] Using token authentication on {$server}...");

        $options = ['impersonation' => $user];

        $this->engine->setOption('ews', [
            'options' => $options,
            'server' => $server,
            'token' => $token,
            'expires_at' => $expires_at,
        ]);

        return API::withCallbackToken($server, $token, $this->apiOptions($options));
    }

    /**
     * Authenticate with OAuth2 (Office365) - get the token
     */
    protected function authenticateWithOAuth2(
        string $server,
        string $user,
        string $client_id,
        string $client_secret,
        string $tenant_id
    ) {
        // See https://github.com/Garethp/php-ews/blob/master/examples/basic/authenticatingWithOAuth.php
        // See https://github.com/Garethp/php-ews/issues/236#issuecomment-1292521527
        // To register OAuth2 app goto https://entra.microsoft.com > Applications > App registrations

        \Log::debug("[EWS] Fetching OAuth2 token from {$server}...");

        $scope = 'https://outlook.office365.com/.default';
        $token_uri = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
        // $authUri = "https://login.microsoftonline.com/{$tenant_id}/oauth2/authorize";

        $response = Http::asForm()
            ->timeout(5)
            ->post($token_uri, [
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'scope' => $scope,
                'grant_type' => 'client_credentials',
            ])
            ->throwUnlessStatus(200);

        $token = $response->json('access_token');

        // Note: Office365 default token expiration time is ~1h,
        $expires_in = $response->json('expires_in');
        $expires_at = now()->addSeconds($expires_in)->toDateTimeString();

        return $this->authenticateWithToken($server, $user, $token, $expires_at);
    }

    /**
     * Get folders hierarchy
     */
    public function getFolders($types = []): array
    {
        if (empty($types)) {
            $types = array_values($this->type_map);
        }

        // Create FolderClass filter
        $search = new Type\OrType();
        foreach ($types as $type) {
            $type = array_search($type, $this->type_map);
            $search->addContains(Type\Contains::buildFromArray([
                'FieldURI' => [
                    Type\FieldURI::buildFromArray(['FieldURI' => 'folder:FolderClass']),
                ],
                'Constant' => Type\ConstantValueType::buildFromArray([
                    'Value' => $type,
                ]),
                'ContainmentComparison' => 'Exact',
                'ContainmentMode' => 'FullString',
            ]));
        }

        // Get full folders hierarchy (filtered by folder class)
        // Use of the filter reduces the response size by excluding system folders
        $options = [
            'Traversal' => 'Deep',
            'Restriction' => ['Or' => $search],
        ];

        $folders = $this->api->getChildrenFolders('root', $options);

        $result = [];

        foreach ($folders as $folder) {
            $class = $folder->getFolderClass();
            $type = $this->type_map[$class] ?? null;

            // Skip folder types we do not support (need)
            if (empty($type) || (!empty($types) && !in_array($type, $types))) {
                continue;
            }

            // Note: Folder names are localized, even INBOX
            $name = $fullname = $folder->getDisplayName();
            $id = $folder->getFolderId()->getId();
            $parentId = $folder->getParentFolderId()->getId();

            // Create folder name with full path
            if ($parentId && !empty($result[$parentId])) {
                $fullname = $result[$parentId]->fullname . '/' . $name;
            }

            // Top-level folder, check if it's a special folder we should ignore
            // FIXME: Is there a better way to distinguish user folders from system ones?
            if (
                in_array($fullname, $this->folder_exceptions)
                || str_starts_with($fullname, 'OwaFV15.1All')
            ) {
                continue;
            }

            $result[$id] = Folder::fromArray([
                'id' => $folder->getFolderId()->toArray(true),
                'total' => $folder->getTotalCount(),
                'class' => $class,
                'type' => $this->type_map[$class] ?? null,
                'name' => $name,
                'fullname' => $fullname,
            ]);
        }

        return $result;
    }

    /**
     * Fetching a folder metadata
     */
    public function fetchFolder(Folder $folder): void
    {
        // NOP
    }

    /**
     * Fetch a list of folder items
     */
    public function fetchItemList(Folder $folder, $callback, ImporterInterface $importer): void
    {
        // Job processing - initialize environment
        $this->initEnv($this->engine->queue);

        // The folder is empty, we can stop here
        if (empty($folder->total)) {
            // TODO: Delete all existing items?
            return;
        }

        // Get items already imported
        // TODO: This might be slow and/or memory expensive, we should consider
        // whether storing list of imported items in some cache wouldn't be a better
        // solution. Of course, cache would not get changes in the destination account.
        $existing = $importer->getItems($folder);

        // Create X-MS-ID index for easier search in existing items
        // Note: For some objects we could use UID (events), but for some we don't have UID in Exchange.
        // Also because fetching extra properties here is problematic, we use X-MS-ID.
        $existingIndex = [];
        array_walk(
            $existing,
            static function (&$item, $idx) use (&$existingIndex) {
                if (!empty($item['x-ms-id'])) {
                    [$id, $changeKey] = explode('!', $item['x-ms-id']);
                    $item['changeKey'] = $changeKey;
                    $existingIndex[$id] = $idx;
                    unset($item['x-ms-id']);
                } else {
                    $existingIndex[$idx] = $idx;
                }
            }
        );

        $request = [
            // Exchange's maximum is 1000
            'IndexedPageItemView' => ['MaxEntriesReturned' => 100, 'Offset' => 0, 'BasePoint' => 'Beginning'],
            'ParentFolderIds' => $folder->id,
            'Traversal' => 'Shallow',
            'ItemShape' => [
                'BaseShape' => 'IdOnly',
                'AdditionalProperties' => [
                    'FieldURI' => [
                        ['FieldURI' => 'item:ItemClass'],
                        // ['FieldURI' => 'item:Size'],
                        ['FieldURI' => 'message:InternetMessageId'], // For mail only?
                    ],
                ],
            ],
        ];

        $request = Type::buildFromArray($request);

        // Note: It is not possible to get mimeContent with FindItem request
        //       That's why we first get the list of object identifiers and
        //       then call GetItem on each separately.

        // TODO: It might be feasible to get all properties for object types
        //       for which we don't use MimeContent, for better performance.

        // Request first page
        $response = $this->api->getClient()->FindItem($request);

        $set = new ItemSet();
        $itemCount = 0;

        // @phpstan-ignore-next-line
        foreach ($response->getItems() as $item) {
            $itemCount++;
            if ($item = $this->toItem($item, $folder, $existing, $existingIndex)) {
                $set->items[] = $item;
                if (count($set->items) == self::CHUNK_SIZE) {
                    $callback($set);
                    $set = new ItemSet();
                }
            }
        }

        // Request other pages until we got all
        while (!$response->isIncludesLastItemInRange()) {
            // @phpstan-ignore-next-line
            $response = $this->api->getNextPage($response);

            foreach ($response->getItems() as $item) {
                $itemCount++;
                if ($item = $this->toItem($item, $folder, $existing, $existingIndex)) {
                    $set->items[] = $item;
                    if (count($set->items) == self::CHUNK_SIZE) {
                        $callback($set);
                        $set = new ItemSet();
                    }
                }
            }
        }

        if (count($set->items)) {
            $callback($set);
        }
        \Log::debug("[EWS] Processed {$itemCount} items");

        // TODO: Delete items that do not exist anymore?
    }

    /**
     * Fetching an item
     */
    public function fetchItem(Item $item): void
    {
        // Job processing - initialize environment
        $this->initEnv($this->engine->queue);

        if ($driver = EWS\Item::factory($this, $item)) {
            $driver->processItem($item);
            return;
        }

        throw new \Exception("Failed to fetch an item from EWS");
    }

    /**
     * Get the source account
     */
    public function getSourceAccount(): Account
    {
        return $this->engine->source;
    }

    /**
     * Get the destination account
     */
    public function getDestinationAccount(): Account
    {
        return $this->engine->destination;
    }

    /**
     * Synchronize specified object
     */
    protected function toItem(Type $item, Folder $folder, $existing, $existingIndex): ?Item
    {
        $id = $item->getItemId()->toArray();
        $exists = null;

        // Detect an existing item, skip if nothing changed
        if (isset($existingIndex[$id['Id']])) {
            $idx = $existingIndex[$id['Id']];

            if ($existing[$idx]['changeKey'] == $id['ChangeKey']) {
                \Log::debug("[EWS] Skipping over already existing message {$idx} with class {$item->getItemClass()}...");
                return null;
            }

            $exists = $existing[$idx]['href'];
        } else {
            $msgid = null;
            try {
                $msgid = $item->getInternetMessageId();
            } catch (\Exception $e) {
                // Ignore
            }
            if (isset($existingIndex[$msgid])) {
                // If the messageid already exists, we assume it's the same email.
                // Flag/size changes are ignored for now.
                // Otherwise we should set uid/size/flags on exists, so the IMAP implementation can pick it up.
                \Log::debug(
                    "[EWS] Skipping over already existing message {$msgid} with class {$item->getItemClass()}..."
                );
                return null;
            }
        }

        if (!EWS\Item::isValidItem($item)) {
            \Log::warning("[EWS] Encountered unhandled item class {$item->getItemClass()}");
            return null;
        }

        return Item::fromArray([
            'id' => $id['Id'],
            'class' => $item->getItemClass(),
            'folder' => $folder,
            'existing' => $exists,
        ]);
    }

    /**
     * Set common API options
     */
    protected function apiOptions(array $options): array
    {
        if (empty($options['version'])) {
            $options['version'] = API\ExchangeWebServices::VERSION_2013;
        }

        // In debug mode record all responses
        if (\config('app.debug')) {
            $options['httpPlayback'] = [
                'mode' => 'record',
                'recordLocation' => \storage_path('ews'),
            ];
        }

        // Options for testing
        foreach (['httpClient', 'httpPlayback'] as $opt) {
            if (($val = $this->engine->getOption($opt)) !== null) {
                $options[$opt] = $val;
            }
        }

        return $options;
    }

    /**
     * Initialize environment for job execution
     *
     * @param Queue $queue Queue
     */
    protected function initEnv(Queue $queue): void
    {
        $ews = $queue->data['options']['ews'];

        if (!empty($ews['token'])) {
            // TODO: Refresh the token if needed
            $this->api = API::withCallbackToken(
                $ews['server'],
                $ews['token'],
                $this->apiOptions($ews['options'])
            );
        } else {
            $this->api = API::withUsernameAndPassword(
                $ews['server'],
                $this->account->username,
                $this->account->password,
                $this->apiOptions($ews['options'])
            );
        }
    }
}
