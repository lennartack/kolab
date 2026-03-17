# Rate Limit Policy

Mail submission requests are rate limited. For this we configure mail exchanger (Postfix)
to check the sender's address in the policy service, which is part of the Kolab Cockpit and
uses HTTP API.

## Rules

Here's the rules applied to a submission (in order):

1. Mail from soft-deleted or suspended senders is put on HOLD.
2. Whitelisted senders are NOT rate limited (see the Whitelists section below).
3. Accounts with 100% discount are NOT rate limited.
4. Accounts with positive balance and any payments are NOT rate limited.
5. If a sender or all users in an account, in last 60 minutes:
    a) sent at least `RATELIMIT_MAX_MESSAGES` (default: 10) messages or
    b) sent messages to at least `RATELIMIT_MAX_RECIPIENTS` (default: 250) recipients,
    sumbission if DEFER-ed.

## Automatic suspending

A sender (or the whole account) created in last two months gets suspended if the submission rate
is exceeded too much. Limits are:

- count of messages in last 60 minutes: (`RATELIMIT_MAX_MESSAGES * RATELIMIT_SUSPEND_FACTOR`)
- count of recipients in last 60 minutes: (`RATELIMIT_MAX_RECIPIENTS * RATELIMIT_SUSPEND_FACTOR`)

## Whitelists

There is an exceptions list for users and domains. Mail from such senders is NOT rate limited.
The whitelist can be managed in command line using these three commands:

```
$ php artisan policy:ratelimit:whitelist:create {object}
$ php artisan policy:ratelimit:whitelist:delete {object}
$ php artisan policy:ratelimit:whitelist:read
```

There is an additional static whitelist available in configuration (`RATELIMIT_WHITELIST`).
This list accepts full email addresses, no domains.
