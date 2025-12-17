<?php

namespace App\Policy;

use App\Policy\Greylist\Connect;
use App\Policy\Greylist\Whitelist;
use App\Policy\Utils as PolicyUtils;
use App\Utils;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class Greylist
{
    protected $connect;
    protected $header;
    protected $netID;
    protected $netType;
    protected $recipientHash;
    protected $recipientID;
    protected $recipientType;
    protected $sender;
    protected $senderLocal = 'unknown';
    protected $senderDomain = 'unknown';
    protected $timestamp;
    protected $whitelist;
    protected $request = [];

    public const CONNECTION_MIN_AGE_MINUTES = 1;
    public const CONNECTION_MAX_AGE_DAYS = 30;
    public const CONNECTION_WHITELIST_THRESHOLD = 5;
    public const CONNECTION_WHITELIST_MAX_AGE_MONTHS = 2;

    /**
     * Class constructor
     *
     * @param array $request Request input
     */
    public function __construct($request)
    {
        $this->request = $request;

        if (array_key_exists('timestamp', $this->request)) {
            $this->timestamp = Carbon::parse($this->request['timestamp']);
        } else {
            $this->timestamp = Carbon::now();
        }
    }

    /**
     * Policy request handler
     *
     * @param array $data Request data
     */
    public static function handle($data): Response
    {
        $request = new self($data);

        if ($request->shouldDefer()) {
            return new Response(Response::ACTION_DEFER_IF_PERMIT, 'Greylisted for 5 minutes. Try again later.', 403);
        }

        $response = new Response(); // DUNNO
        $response->prepends[] = $request->headerGreylist();

        return $response;
    }

    /**
     * Get request state headers (Received-Greylist) - after self::shouldDefer() call
     */
    public function headerGreylist(): string
    {
        if ($this->whitelist) {
            if ($this->whitelist->sender_local) {
                return sprintf(
                    "Received-Greylist: sender %s whitelisted since %s (UTC)",
                    $this->sender,
                    $this->whitelist->created_at->toDateTimeString()
                );
            }

            return sprintf(
                "Received-Greylist: domain %s from %s whitelisted since %s (UTC)",
                $this->senderDomain,
                $this->request['client_address'],
                $this->whitelist->created_at->toDateTimeString()
            );
        }

        if ($this->connect) {
            return sprintf(
                "Received-Greylist: greylisted from %s until %s.",
                $this->connect->created_at->toDateTimeString(),
                $this->timestamp->toDateTimeString()
            );
        }

        return "Received-Greylist: no opinion here";
    }

    /**
     * Connection check regarding greylisting policy
     *
     * @return bool True if the message should be put off, False otherwise
     */
    public function shouldDefer(): bool
    {
        [$this->netID, $this->netType] = Utils::getNetFromAddress($this->request['client_address']);

        if (!$this->netID) {
            return true;
        }

        $recipient = $this->recipientFromRequest();

        $this->sender = Utils::normalizeAddress($this->request['sender']);

        if (str_contains($this->sender, '@')) {
            [$this->senderLocal, $this->senderDomain] = explode('@', $this->sender);
        }

        if (strlen($this->senderLocal) > 255) {
            $this->senderLocal = substr($this->senderLocal, 0, 255);
        }

        // Get the most recent entry
        $connect = $this->findConnectsCollection()->first();

        // Purge all old information if we have no recent entry
        if ($connect && $connect->updated_at < $this->timestamp->copy()->subDays(self::CONNECTION_MAX_AGE_DAYS)) {
            $this->findConnectsCollection()->delete();
            $connect = null;
        }

        // See if the recipient opted-out of the feature
        $enabled = true;
        if ($recipient) {
            $enabled = PolicyUtils::getPolicySetting($recipient, 'greylist_enabled');
        }

        // FIXME: Shouldn't we bail-out (return early) if there's no $recipient?

        // the following block is to maintain statistics and state ...

        // determine if the sender domain is a whitelist from this network
        $this->whitelist = Whitelist::where('sender_domain', $this->senderDomain)
            ->where('net_id', $this->netID)
            ->where('net_type', $this->netType)
            ->first();

        $cutoffDate = $this->timestamp->copy()->subDays(self::CONNECTION_MAX_AGE_DAYS)->startOfDay();

        DB::beginTransaction();

        // Whitelist older than a month, delete it
        if ($this->whitelist && $this->whitelist->updated_at < $this->timestamp->copy()->subMonthsWithoutOverflow(self::CONNECTION_WHITELIST_MAX_AGE_MONTHS)) {
            $this->whitelist->delete();
            $this->whitelist = null;
        }

        $all = Connect::where('sender_domain', $this->senderDomain)
            ->where('net_id', $this->netID)
            ->where('net_type', $this->netType)
            ->where('updated_at', '>=', $cutoffDate->toDateTimeString());

        // "Touch" the whitelist if exists
        if ($this->whitelist) {
            // For performance reasons do not update timestamp more than once per 1 minute
            // FIXME: Such granularity should be good enough, right? It might be a problem
            // if you wanted to compare this timestamp with mail log entries.
            if ($this->whitelist->updated_at < $this->timestamp->copy()->subMinute()) {
                $this->whitelist->updated_at = $this->timestamp;
                $this->whitelist->save(['timestamps' => false]);
            }

            $all->where('greylisting', true)
                ->update(['greylisting' => false, 'updated_at' => $this->timestamp]);

            $enabled = false;
        } elseif ($all->count() >= (self::CONNECTION_WHITELIST_THRESHOLD - 1)) {
            // Automatically create a whitelist if we have at least 5 (4 existing plus this) messages from the sender
            $this->whitelist = Whitelist::create([
                'sender_domain' => $this->senderDomain,
                'net_id' => $this->netID,
                'net_type' => $this->netType,
                'created_at' => $this->timestamp,
                'updated_at' => $this->timestamp,
            ]);

            $all->where('greylisting', true)
                ->update(['greylisting' => false, 'updated_at' => $this->timestamp]);
        }

        // TODO: determine if the sender (individual) is a whitelist

        // TODO: determine if the sender is a penpal of any of the recipients. First recipient wins.

        if (!$enabled) {
            DB::commit();
            return false;
        }

        $defer = true;

        // Update/Create an entry for the sender/recipient/net combination
        if ($connect) {
            $connect->connect_count++;

            // TODO: The period of time for which the greylisting persists is configurable.
            if ($connect->created_at < $this->timestamp->copy()->subMinutes(self::CONNECTION_MIN_AGE_MINUTES)) {
                $defer = false;

                $connect->greylisting = false;
            }

            $connect->save();
        } else {
            $connect = Connect::create([
                'sender_local' => $this->senderLocal,
                'sender_domain' => $this->senderDomain,
                'net_id' => $this->netID,
                'net_type' => $this->netType,
                'recipient_hash' => $this->recipientHash,
                'recipient_id' => $this->recipientID,
                'recipient_type' => $this->recipientType,
                'created_at' => $this->timestamp,
                'updated_at' => $this->timestamp,
            ]);
        }

        $this->connect = $connect;

        DB::commit();

        return $defer;
    }

    protected function findConnectsCollection()
    {
        return Connect::where([
            'sender_local' => $this->senderLocal,
            'sender_domain' => $this->senderDomain,
            'recipient_hash' => $this->recipientHash,
            'net_id' => $this->netID,
            'net_type' => $this->netType,
        ]);
    }

    protected function recipientFromRequest()
    {
        $recipients = PolicyUtils::findObjectsByRecipientAddress($this->request['recipient']);

        if (count($recipients) > 1) {
            \Log::warning(
                "Only taking the first recipient from the request for {$this->request['recipient']}"
            );
        }

        if (count($recipients) >= 1) {
            foreach ($recipients as $recipient) {
                $this->recipientID = $recipient->id;
                $this->recipientType = $recipient::class;
                break;
            }
        } else {
            $recipient = null;
        }

        $this->recipientHash = hash('sha256', Utils::normalizeAddress($this->request['recipient']));

        return $recipient;
    }
}
