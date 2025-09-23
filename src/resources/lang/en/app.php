<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used in the application.
    */

    'chart-all' => 'All',
    'chart-created' => 'Created',
    'chart-deleted' => 'Deleted',
    'chart-suspended' => 'Suspended',
    'chart-average' => 'average',
    'chart-allusers' => 'All Users - last year',
    'chart-discounts' => 'Discounts',
    'chart-vouchers' => 'Vouchers',
    'chart-income' => 'Income in :currency - last 8 weeks',
    'chart-payers' => 'Payers - last year',
    'chart-users' => 'Users - last 8 weeks',
    'chart-users-per-country' => 'Users per country',

    'code-verified-email' => 'The external email address has been verified.',

    'companion-create-success' => 'Companion app has been created.',
    'companion-delete-success' => 'Companion app has been removed.',

    'device-claim-success' => 'The device has been claimed successfully.',

    'event-suspended' => 'Suspended',
    'event-unsuspended' => 'Unsuspended',
    'event-comment' => 'Commented',
    'event-mailsent' => 'Mail Sent',

    'mandate-delete-success' => 'The auto-payment has been removed.',
    'mandate-update-success' => 'The auto-payment has been updated.',

    'mandate-description-suffix' => 'Auto-Payment Setup',

    'other' => 'Other',
    'planbutton' => 'Choose :plan',

    'process-async' => 'Setup process has been pushed. Please wait.',
    'process-user-new' => 'Registering a user...',
    'process-user-ldap-ready' => 'Creating a user...',
    'process-user-imap-ready' => 'Creating a mailbox...',
    'process-domain-new' => 'Registering a custom domain...',
    'process-domain-ldap-ready' => 'Creating a custom domain...',
    'process-domain-verified' => 'Verifying a custom domain...',
    'process-domain-confirmed' => 'Confirming an ownership of a custom domain...',
    'process-success' => 'Setup process finished successfully.',
    'process-error-distlist-ldap-ready' => 'Failed to create a distribution list.',
    'process-error-domain-ldap-ready' => 'Failed to create a domain.',
    'process-error-domain-verified' => 'Failed to verify a domain.',
    'process-error-domain-confirmed' => 'Failed to confirm an ownership of a domain.',
    'process-error-resource-imap-ready' => 'Failed to verify that a shared folder exists.',
    'process-error-resource-ldap-ready' => 'Failed to create a resource.',
    'process-error-shared-folder-imap-ready' => 'Failed to verify that a shared folder exists.',
    'process-error-shared-folder-ldap-ready' => 'Failed to create a shared folder.',
    'process-error-user-ldap-ready' => 'Failed to create a user.',
    'process-error-user-imap-ready' => 'Failed to verify that a mailbox exists.',
    'process-distlist-new' => 'Registering a distribution list...',
    'process-distlist-ldap-ready' => 'Creating a distribution list...',
    'process-resource-new' => 'Registering a resource...',
    'process-resource-imap-ready' => 'Creating a shared folder...',
    'process-resource-ldap-ready' => 'Creating a resource...',
    'process-shared-folder-new' => 'Registering a shared folder...',
    'process-shared-folder-imap-ready' => 'Creating a shared folder...',
    'process-shared-folder-ldap-ready' => 'Creating a shared folder...',

    'discount-code' => 'Discount: :code',

    'distlist-update-success' => 'Distribution list updated successfully.',
    'distlist-create-success' => 'Distribution list created successfully.',
    'distlist-delete-success' => 'Distribution list deleted successfully.',
    'distlist-suspend-success' => 'Distribution list suspended successfully.',
    'distlist-unsuspend-success' => 'Distribution list unsuspended successfully.',
    'distlist-setconfig-success' => 'Distribution list settings updated successfully.',

    'delegation-create-success' => 'Delegation created successfully.',
    'delegation-delete-success' => 'Delegation deleted successfully.',

    'domain-create-success' => 'Domain created successfully.',
    'domain-delete-success' => 'Domain deleted successfully.',
    'domain-notempty-error' => 'Unable to delete a domain with assigned users or other objects.',
    'domain-confirm-success' => 'Domain ownership confirmed successfully.',
    'domain-confirm-error' => 'Domain ownership confirmation failed.',
    'domain-suspend-success' => 'Domain suspended successfully.',
    'domain-unsuspend-success' => 'Domain unsuspended successfully.',
    'domain-setconfig-success' => 'Domain settings updated successfully.',

    'file-create-success' => 'File created successfully.',
    'file-delete-success' => 'File deleted successfully.',
    'file-update-success' => 'File updated successfully.',
    'file-permissions-create-success' => 'File permissions created successfully.',
    'file-permissions-update-success' => 'File permissions updated successfully.',
    'file-permissions-delete-success' => 'File permissions deleted successfully.',

    'collection-create-success' => 'Collection created successfully.',
    'collection-delete-success' => 'Collection deleted successfully.',
    'collection-update-success' => 'Collection updated successfully.',

    'payment-status-paid' => 'The payment has been completed successfully.',
    'payment-status-canceled' => 'The payment has been canceled.',
    'payment-status-failed' => 'The payment failed.',
    'payment-status-expired' => 'The payment expired.',
    'payment-status-checking' => "The payment hasn't been completed yet. Checking the status...",

    'period-year' => 'year',
    'period-month' => 'month',

    'resource-update-success' => 'Resource updated successfully.',
    'resource-create-success' => 'Resource created successfully.',
    'resource-delete-success' => 'Resource deleted successfully.',
    'resource-setconfig-success' => 'Resource settings updated successfully.',

    'room-update-success' => 'Room updated successfully.',
    'room-create-success' => 'Room created successfully.',
    'room-delete-success' => 'Room deleted successfully.',
    'room-setconfig-success' => 'Room configuration updated successfully.',
    'room-unsupported-option-error' => 'Invalid room configuration option.',

    'shared-folder-update-success' => 'Shared folder updated successfully.',
    'shared-folder-create-success' => 'Shared folder created successfully.',
    'shared-folder-delete-success' => 'Shared folder deleted successfully.',
    'shared-folder-setconfig-success' => 'Shared folder settings updated successfully.',

    'user-update-success' => 'User data updated successfully.',
    'user-create-success' => 'User created successfully.',
    'user-delete-success' => 'User deleted successfully.',
    'user-resync-success' => 'User synchronization has been started.',
    'user-suspend-success' => 'User suspended successfully.',
    'user-unsuspend-success' => 'User unsuspended successfully.',
    'user-reset-2fa-success' => '2-Factor authentication reset successfully.',
    'user-reset-geo-lock-success' => 'Geo-lockin setup reset successfully.',
    'user-setconfig-success' => 'User settings updated successfully.',
    'user-set-sku-success' => 'The subscription added successfully.',
    'user-set-sku-already-exists' => 'The subscription already exists.',

    'search-foundxdomains' => ':x domains have been found.',
    'search-foundxdistlists' => ':x distribution lists have been found.',
    'search-foundxresources' => ':x resources have been found.',
    'search-foundxshared-folders' => ':x shared folders have been found.',
    'search-foundxusers' => ':x user accounts have been found.',

    'signup-account-mandate' => 'Now it is required to provide your credit card details.'
        . ' This way you agree to charge you with an appropriate amount of money according to the plan you signed up for.',
    'signup-account-free' => 'You are signing up for an account with 100% discount. You will be redirected immediately to your Dashboard.',
    'signup-plan-monthly' => 'You are choosing a monthly subscription.',
    'signup-plan-yearly' => 'You are choosing a yearly subscription.',
    'signup-subscription-monthly' => 'Monthly subscription',
    'signup-subscription-yearly' => 'Yearly subscription',
    'signup-invitations-created' => 'The invitation has been created.|:count invitations has been created.',
    'signup-invitations-csv-empty' => 'Failed to find any valid email addresses in the uploaded file.',
    'signup-invitations-csv-invalid-email' => 'Found an invalid email address (:email) on line :line.',
    'signup-invitation-delete-success' => 'Invitation deleted successfully.',
    'signup-invitation-resend-success' => 'Invitation added to the sending queue successfully.',

    'support-request-success' => 'Support request submitted successfully.',
    'support-request-error' => 'Failed to submit the support request.',

    'siteuser' => ':site User',
    'total' => 'Total',

    'wallet-award-success' => 'The bonus has been added to the wallet successfully.',
    'wallet-penalty-success' => 'The penalty has been added to the wallet successfully.',
    'wallet-update-success' => 'User wallet updated successfully.',

    'password-reset-code-delete-success' => 'Password reset code deleted successfully.',
    'password-reset-success' => 'Password updated successfully.',
    'password-rule-min' => 'Minimum password length: :param characters',
    'password-rule-max' => 'Maximum password length: :param characters',
    'password-rule-lower' => 'Password contains a lower-case character',
    'password-rule-upper' => 'Password contains an upper-case character',
    'password-rule-digit' => 'Password contains a digit',
    'password-rule-special' => 'Password contains a special character',
    'password-rule-last' => 'Password cannot be the same as the last :param passwords',

    'wallet-notice-date' => 'With your current subscriptions your account balance will last until about :date (:days).',
    'wallet-notice-nocredit' => 'You are out of credit, top up your balance now.',
    'wallet-notice-today' => 'You will run out of credit today, top up your balance now.',
    'wallet-notice-trial' => 'You are in your free trial period.',
    'wallet-notice-trial-end' => 'Your free trial is about to end, top up to continue.',

    'vat-incl' => 'Incl. VAT :vat (:rate of :cost)',
];
