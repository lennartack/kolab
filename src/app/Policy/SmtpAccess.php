<?php

namespace App\Policy;

use App\User;
use App\UserAlias;
use App\Utils;

class SmtpAccess
{
    /**
     * Handle SMTP submission request
     *
     * @param array $data Input data
     */
    public static function submission($data): Response
    {
        // TODO: The old SMTP access policy had an option ('empty_sender_hosts') to allow
        // sending mail with no sender from configured networks.

        [$local, $domain] = Utils::normalizeAddress($data['sender'], true);

        if (empty($local) || empty($domain)) {
            return new Response(Response::ACTION_REJECT, 'Invalid sender', 403);
        }

        $sender = $local . '@' . $domain;

        [$local, $domain] = Utils::normalizeAddress($data['user'], true);

        if (empty($local) || empty($domain)) {
            return new Response(Response::ACTION_REJECT, 'Invalid user', 403);
        }

        $sasl_user = $local . '@' . $domain;

        $user = User::where('email', $sasl_user)->first();

        if (!$user) {
            return new Response(Response::ACTION_REJECT, "Could not find user {$data['user']}", 403);
        }

        if (!self::verifySender($user, $sender)) {
            $reason = "{$sasl_user} is unauthorized to send mail as {$sender}";
            return new Response(Response::ACTION_REJECT, $reason, 403);
        }

        // TODO: should we be using the $user or the $sender?
        $response = RateLimit::verifyRequest($user, (array) $data['recipients']);
        if ($response->action != Response::ACTION_DUNNO) {
            return $response;
        }

        // TODO: Prepending Sender/X-Sender/X-Authenticated-As headers?
        // TODO: Recipient policies here?

        // Leave it up to the postfix configuration how to proceed
        // (accept would stop processing)
        return new Response(Response::ACTION_DUNNO);
    }

    /**
     * Verify whether a user is allowed to send using the envelope sender address.
     *
     * @param User   $user  Authenticated user
     * @param string $email Email address
     */
    public static function verifySender(User $user, string $email): bool
    {
        if ($user->isSuspended() || !str_contains($email, '@')) {
            return false;
        }

        // TODO: Make sure the domain is not suspended
        // TODO: Email might belong to a group (distlists), check group's sender_policy
        // TODO: Email might be a shared folder (or it's alias)?

        $email = \strtolower($email);

        if ($user->email == $email) {
            return true;
        }

        // noreply@ user can impersonate everyone
        if ($user->email == \config('mail.mailers.smtp.username')) {
            return true;
        }

        // Is it one of user's aliases?
        $alias = $user->aliases()->where('alias', $email)->first();

        if ($alias) {
            return true;
        }

        // Delegation
        if (\config('app.with_delegation')) {
            // Is it another user's email?
            $other_users = User::where('email', $email)->pluck('id')->all();

            if (!count($other_users)) {
                // Is it another user's alias?
                $other_users = UserAlias::where('alias', $email)->pluck('user_id')->all();
            }

            if (count($other_users)) {
                // Is the user a delegatee of that other user? Is he suspended?
                $is_delegate = $user->delegators()->whereIn('user_id', $other_users)
                    ->whereNot('users.status', '&', User::STATUS_SUSPENDED)
                    ->exists();

                if ($is_delegate) {
                    return true;
                }
            }
        }

        return false;
    }
}
