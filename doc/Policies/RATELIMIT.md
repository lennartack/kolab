# Rate Limit Policy

Mail submission requests are rate limited. For this we configure mail exchanger (Postfix)
to check the sender's address in the policy service, which is part of the Kolab Cockpit and
uses HTTP API.

## Rules

Here's the rules applied to a submission (in order):

1. Mail from soft-deleted or suspended senders is put on HOLD.
2. Whitelisted senders are NOT rate limited (see the Whitelists section below).
3. If a sender or all users in an account, in last 60 minutes sent messages to
   at least `RATELIMIT_MAX_RECIPIENTS` (default: 100) recipients, sumbission is DEFER-ed.
   The limit for restricted (new) accounts is different (`RATELIMIT_MAX_RECIPIENTS_RESTRICTED`),
   and defaults to 1/4th of the limit for non-restricted accounts.
4. If a sender or all users in an account, in last 24 hours sent messages to
   at least `RATELIMIT_MAX_RECIPIENTS_DAILY` (default: 1000) recipients, sumbission is DEFER-ed.
   The limit for restricted accounts is different (`RATELIMIT_MAX_RECIPIENTS_RESTRICTED_DAILY`),
   and defaults to 1/4th of the limit for non-restricted accounts.

## Automatic suspending

A sender (or the whole account) gets suspended if the submission rate is exceeded too much.

1. Limit to number of recipients in last 60 minutes is:
    - for all accounts: `RATELIMIT_SUSPEND_MAX_RECIPIENTS`
    - for restricted accounts: `RATELIMIT_SUSPEND_MAX_RECIPIENTS_RESTRICTED`
2. Limit to number of recipients in last 24 hours is:
    - for all accounts: `RATELIMIT_SUSPEND_MAX_RECIPIENTS_DAILY`
    - for restricted accounts: `RATELIMIT_SUSPEND_MAX_RECIPIENTS_RESTRICTED_DAILY`

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
