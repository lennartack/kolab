<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mail Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used in mail/sms messages sent by the app.
    */

    'header' => "Dear :name,",
    'footer1' => "Best regards,",
    'footer2' => "Your :site Team",

    'more-info-html' => "See <a href=\":href\">here</a> for more information.",
    'more-info-text' => "See :href for more information.",

    'degradedaccountreminder-subject' => ":site Reminder: Your account is free",
    'degradedaccountreminder-body1' => "Thanks for sticking around, we remind you your :email account is a free "
        . "account and restricted to receiving email, and use of the web client and cockpit only.",
    'degradedaccountreminder-body2' => "This leaves you with an ideal account to use for account registration with third parties "
        . "and password resets, notifications or even just subscriptions to newsletters and the like.",
    'degradedaccountreminder-body3' => "To regain functionality such as sending email, calendars, address books, phone synchronization "
        . "and voice & video conferencing, log on to the cockpit and make sure you have a positive account balance.",
    'degradedaccountreminder-body4' => "You can also delete your account there, making sure your data disappears from our systems.",
    'degradedaccountreminder-body5' => "Thank you for your consideration!",

    'emailverification-subject' => ":site Verification",
    'emailverification-body1' => "This is the verification code to validate eligibility of an email address provided in :site account settings.",
    'emailverification-body2' => "",

    'itip-cancel-subject' => "\":summary\" has been canceled",
    'itip-cancel-body' => "The event \":summary\" at :start has been canceled by the organizer."
        . " The copy in your calendar has been removed accordingly.",

    'itip-reply-subject' => "\":summary\" has been updated",
    'itip-reply-body' => "The event \":summary\" at :start has been updated in your calendar.",

    'itip-request-subject' => "\":summary\" has been updated",
    'itip-request-body' => "The event \":summary\" at :start has been updated in your calendar.",

    'itip-attendee-accepted' => ":sender accepted the invitation.",
    'itip-attendee-declined' => ":sender declined the invitation.",
    'itip-attendee-tentative' => ":sender accepted the invitation tentatively.",
    'itip-attendee-delegated' => ":sender delegated the invitation to :delegatee.",

    'itip-recurrence-note' => "NOTE: This only refers to this single occurrence!",
    'itip-footer' => "This is an automated message. Please do not reply.",
    'itip-comment' => ":sender provided comment: :comment",

    'negativebalance-subject' => ":site Payment Required",
    'negativebalance-body' => "This is a notification to let you know that your :email account balance has run into the negative and requires your attention. "
        . "Consider setting up an automatic payment to avoid messages like this in the future.",
    'negativebalance-body-ext' => "Settle up to keep your account running:",

    'negativebalancereminderdegrade-subject' => ":site Payment Reminder",
    'negativebalancereminderdegrade-body' => "It has probably skipped your attention that you are behind on paying for your :email account. "
        . "Consider setting up an automatic payment to avoid messages like this in the future.",
    'negativebalancereminderdegrade-body-ext' => "Settle up to keep your account running:",

    'negativebalancereminderdegrade-body-warning' => "Please, be aware that your account will be degraded "
        . "if your account balance is not settled by :date.",

    'negativebalancedegraded-subject' => ":site Account Degraded",
    'negativebalancedegraded-body' => "Your :email account has been degraded for having a negative balance for too long. "
        . "Consider setting up an automatic payment to avoid messages like this in the future.",
    'negativebalancedegraded-body-ext' => "Settle up now to undegrade your account:",

    'passwordreset-subject' => ":site Password Reset",
    'passwordreset-body1' => "Someone recently asked to change your :email password.",
    'passwordreset-body2' => "If this was you, use this verification code to complete the process:",
    'passwordreset-body3' => "You can also click the link below:",
    'passwordreset-body4' => "If you did not make such a request, you can either ignore this message or get in touch with us about this incident.",

    'passwordexpiration-subject' => ":site password expires on :date",
    'passwordexpiration-body' => "Your :email account password will expire on :date. You can change it here:",

    'paymentmandatedisabled-subject' => ":site Auto-payment Problem",
    'paymentmandatedisabled-body' => "Your :email account balance is negative "
        . "and the configured amount for automatically topping up the balance does not cover "
        . "the costs of subscriptions consumed.",
    'paymentmandatedisabled-body-ext' => "Charging you multiple times for the same amount in short succession "
        . "could lead to issues with the payment provider. "
        . "In order to not cause any problems, we suspended auto-payment for your account. "
        . "To resolve this issue, login to your account settings and adjust your auto-payment amount.",

    'paymentfailure-subject' => ":site Payment Failed",
    'paymentfailure-body' => "Something went wrong with auto-payment for your :email account.\n"
        . "We tried to charge you via your preferred payment method, but the charge did not go through.",
    'paymentfailure-body-ext' => "In order to not cause any further issues, we suspended auto-payment for your account. "
        . "To resolve this issue, login to your account settings at",
    'paymentfailure-body-rest' => "There you can pay manually for your account and "
        . "change your auto-payment settings.",

    'paymentsuccess-subject' => ":site Payment Succeeded",
    'paymentsuccess-body' => "The auto-payment for your :email account went through without issues. "
        . "You can check your new account balance and more details here:",

    'support' => "Special circumstances? Something is wrong with a charge?\n"
        . ":site Support is here to help.",

    'signupverification-subject' => ":site Registration",
    'signupverification-header' => "Hi,",
    'signupverification-body1' => "This is your verification code for the :site registration process:",
    'signupverification-body2' => "You can also click the link below to continue the registration process:",

    'signupinvitation-subject' => ":site Invitation",
    'signupinvitation-header' => "Hi,",
    'signupinvitation-body1' => "You have been invited to join :site. Click the link below to sign up.",
    'signupinvitation-body2' => "",

    'trialend-subject' => ":site: Your trial phase has ended",
    'trialend-intro' => "We hope you enjoyed the 30 days of free :site trial."
        . " Your subscriptions become active after the first month of use and the fee is due after another month.",
    'trialend-kb' => "You can read about how to pay the subscription fee in this knowledge base article:",
    'trialend-body1' => "You can leave :site at any time, there is no contractual minimum period"
        . " and your account will NOT be deleted automatically."
        . " You can delete your account via the red [Delete account] button in your profile."
        . " This will end your subscription and delete all relevant data.",
    'trialend-body2' => "THIS OPERATION IS IRREVERSIBLE!",
    'trialend-body3' => "When data is deleted it can not be recovered."
        . " Please make sure that you have saved all data that you need before pressing the red button."
        . " Do not hesitate to contact Support with any questions or concerns.",
];
