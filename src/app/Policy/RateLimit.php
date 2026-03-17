<?php

namespace App\Policy;

use App\Traits\BelongsToUserTrait;
use App\Transaction;
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

        if (in_array($sender, \config('app.ratelimit_whitelist', []), true)) {
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

        // exempt owners that have 100% discount.
        if ($wallet->discount && $wallet->discount->discount == 100) {
            return new Response(Response::ACTION_DUNNO);
        }

        // exempt owners that currently maintain a positive balance and made any payments.
        // Because there might be users that pay via external methods (and don't have Payment records)
        // we can't check only the Payments table. Instead we assume that a credit/award transaction
        // is enough to consider the user a "paying user" for purpose of the rate limit.
        if ($wallet->balance > 0) {
            $isPayer = $wallet->transactions()
                ->whereIn('type', [Transaction::WALLET_AWARD, Transaction::WALLET_CREDIT])
                ->where('amount', '>', 0)
                ->exists();

            if ($isPayer) {
                return new Response(Response::ACTION_DUNNO);
            }
        }

        $max_messages = config('app.ratelimit_max_messages');
        $max_recipients = config('app.ratelimit_max_recipients');
        $suspend_factor = config('app.ratelimit_suspend_factor');

        $ageThreshold = Carbon::now()->subMonthsWithoutOverflow(2);

        // Examine the rates at which the owner (or its users) is sending
        $ownerRates = self::where('owner_id', $owner->id)
            ->where('updated_at', '>=', Carbon::now()->subHour());

        if (($count = $ownerRates->count()) >= $max_messages) {
            // automatically suspend (recursively) if X times over the original limit and younger than two months
            if ($count >= $max_messages * $suspend_factor && $owner->created_at > $ageThreshold) {
                $owner->suspendAccount();
            }

            return new Response(
                Response::ACTION_DEFER_IF_PERMIT,
                "The account is at {$max_messages} messages per hour, cool down.",
                403
            );
        }

        if (($recipientCount = $ownerRates->sum('recipient_count')) >= $max_recipients) {
            // automatically suspend if X times over the original limit and younger than two months
            if ($recipientCount >= $max_recipients * $suspend_factor && $owner->created_at > $ageThreshold) {
                $owner->suspendAccount();
            }

            return new Response(
                Response::ACTION_DEFER_IF_PERMIT,
                "The account is at {$max_recipients} recipients per hour, cool down.",
                403
            );
        }

        // Examine the rates at which the user is sending (if not also the owner)
        if ($user->id != $owner->id) {
            $userRates = self::where('user_id', $user->id)
                ->where('updated_at', '>=', Carbon::now()->subHour());

            if (($count = $userRates->count()) >= $max_messages) {
                // automatically suspend if X times over the original limit and younger than two months
                if ($count >= $max_messages * $suspend_factor && $user->created_at > $ageThreshold) {
                    $user->suspend();
                }

                return new Response(
                    Response::ACTION_DEFER_IF_PERMIT,
                    "User is at {$max_messages} messages per hour, cool down.",
                    403
                );
            }

            if (($recipientCount = $userRates->sum('recipient_count')) >= $max_recipients) {
                // automatically suspend if X times over the original limit
                if ($recipientCount >= $max_recipients * $suspend_factor && $user->created_at > $ageThreshold) {
                    $user->suspend();
                }

                return new Response(
                    Response::ACTION_DEFER_IF_PERMIT,
                    "The account is at {$max_recipients} recipients per hour, cool down.",
                    403
                );
            }
        }

        return new Response(Response::ACTION_DUNNO);
    }
}
