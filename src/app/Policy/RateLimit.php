<?php

namespace App\Policy;

use App\EventLog;
use App\Traits\BelongsToUserTrait;
use App\User;
use App\UserAlias;
use App\Utils;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class RateLimit extends Model
{
    use BelongsToUserTrait;

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = [
        'user_id',
        'owner_id',
        'recipient_hash',
        'recipient_count',
    ];

    /** @var string Database table name */
    protected $table = 'policy_ratelimit';

    /**
     * Check the submission request agains rate limits
     *
     * @param array $data Request data
     *
     * @return Response Policy respone
     */
    public static function handle($data): Response
    {
        [$local, $domain] = Utils::normalizeAddress($data['sender'], true);

        if (empty($local) || empty($domain)) {
            return new Response(Response::ACTION_HOLD, 'Invalid sender email', 403);
        }

        $sender = $local . '@' . $domain;

        if (in_array($sender, \config('policy.ratelimit.whitelist', []), true)) {
            return new Response(Response::ACTION_DUNNO);
        }

        // Find the Kolab user
        $user = User::withTrashed()->where('email', $sender)->first();

        if (!$user) {
            $alias = UserAlias::where('alias', $sender)->first();

            if (!$alias) {
                // TODO: How about sender is a distlist address?

                // external sender through where this policy is applied
                return new Response(Response::ACTION_DUNNO);
            }

            $user = $alias->user()->withTrashed()->first();
        }

        return self::verifyRequest($user, (array) $data['recipients']);
    }

    /**
     * Check the submission request agains rate limits
     *
     * @param User  $user       Sender user
     * @param array $recipients List of mail recipients
     *
     * @return Response Policy respone
     */
    public static function verifyRequest(User $user, array $recipients = []): Response
    {
        if ($user->trashed() || $user->isSuspended()) {
            // use HOLD, so that it is silent (as opposed to REJECT)
            return new Response(Response::ACTION_HOLD, 'Sender deleted or suspended', 403);
        }

        // Examine the domain
        $domain = $user->domain();

        if (!$domain) {
            // external sender through where this policy is applied
            return new Response(Response::ACTION_DUNNO);
        }

        if ($domain->trashed() || $domain->isSuspended()) {
            // use HOLD, so that it is silent (as opposed to REJECT)
            return new Response(Response::ACTION_HOLD, 'Sender domain deleted or suspended', 403);
        }

        // see if the user or domain is whitelisted
        // use ./artisan policy:ratelimit:whitelist:create <email|namespace>
        if (RateLimit\Whitelist::isListed($user) || RateLimit\Whitelist::isListed($domain)) {
            return new Response(Response::ACTION_DUNNO);
        }

        // Retrieve the wallet to get to the owner
        $wallet = $user->wallet();

        // wait, there is no wallet?
        if (!$wallet || !$wallet->owner) {
            return new Response(Response::ACTION_HOLD, 'Sender without a wallet', 403);
        }

        $owner = $wallet->owner;

        // user nor domain whitelisted, continue scrutinizing the request
        // TODO: Exclude local users from the count? Could be expensive, but at least exclude the same domain as the sender?
        sort($recipients);
        $recipientCount = count($recipients);
        $recipientHash = hash('sha256', implode(',', $recipients));

        // find or create the request
        $request = self::where('recipient_hash', $recipientHash)
            ->where('user_id', $user->id)
            ->where('updated_at', '>=', Carbon::now()->subHour())
            ->first();

        if (!$request) {
            $request = self::create([
                'user_id' => $user->id,
                'owner_id' => $owner->id,
                'recipient_hash' => $recipientHash,
                'recipient_count' => $recipientCount,
            ]);
        } else {
            // ensure the request has an up to date timestamp
            $request->updated_at = Carbon::now();
            $request->save();
        }

        // Examine the hourly rates at which the account is sending
        if ($error = self::checkLimits($user, $owner, false)) {
            return new Response(Response::ACTION_DEFER_IF_PERMIT, $error, 403);
        }

        // Examine the daily rates at which the account is sending
        if ($error = self::checkLimits($user, $owner, true)) {
            return new Response(Response::ACTION_DEFER_IF_PERMIT, $error, 403);
        }

        return new Response(Response::ACTION_DUNNO);
    }

    /**
     * Check number of recipients limit (per hour or per day)
     */
    private static function checkLimits($user, $owner, bool $daily = false): ?string
    {
        $suffix = $daily ? '_daily' : '';

        $max_recipients = config('policy.ratelimit.max_recipients' . $suffix);
        $max_recipients_restricted = config('policy.ratelimit.max_recipients_restricted' . $suffix);
        $suspend_max_recipients = config('policy.ratelimit.suspend_max_recipients' . $suffix);
        $suspend_max_recipients_restricted = config('policy.ratelimit.suspend_max_recipients_restricted' . $suffix);

        // New users should get a lower limit
        if ($owner->isRestricted()) {
            if (!$max_recipients_restricted) {
                $max_recipients_restricted = (int) ($max_recipients / 4);
            }

            $max_recipients = $max_recipients_restricted;
        }

        if (!$max_recipients) {
            return null;
        }

        $start = Carbon::now()->subHours($daily ? 24 : 1);

        $count = self::where('owner_id', $owner->id)->where('updated_at', '>=', $start)->sum('recipient_count');

        if ($count >= $max_recipients) {
            $type = $daily ? 'daily' : 'hourly';
            \Log::info("[Rate-Limit] {$owner->email} {$type} recipients count: {$count}"
                . ($owner->id != $user->id ? ". Sender: {$user->email}" : ''));

            // New users should get a lower limit for suspension
            if ($owner->isRestricted()) {
                if (!$suspend_max_recipients_restricted) {
                    $suspend_max_recipients_restricted = (int) ($suspend_max_recipients / 4);
                }

                $suspend_max_recipients = $suspend_max_recipients_restricted;
            }

            // automatically suspend if too much over the original limit
            if ($suspend_max_recipients && $count >= $suspend_max_recipients) {
                $owner->suspendAccount();

                // TODO: We could include in the message who sent how many messages
                $msg = "Exceeded {$type} rate limit ({$suspend_max_recipients})";
                EventLog::createFor($owner, EventLog::TYPE_SUSPENDED, $msg);

                \Log::warning("[Rate-Limit] Suspended spammer {$owner->email}"
                    . ($owner->id != $user->id ? ". Sender: {$user->email}" : ''));

                // TODO: Send a notification email to the account owner?
            }

            $type = $daily ? 'per day' : 'per hour';
            return "The account is at {$max_recipients} recipients {$type}, cool down.";
        }

        return null;
    }
}
