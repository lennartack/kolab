<?php

namespace App\Policy\Mailfilter\Notifications;

use App\Mail\Mailable;

class ItipNotificationMail extends Mailable
{
    protected ItipNotificationParams $params;

    /**
     * Create a new message instance.
     *
     * @param ItipNotificationParams $params Mail content parameters
     */
    public function __construct(ItipNotificationParams $params)
    {
        $this->params = $params;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $mode = $this->params->mode ?? 'request';
        $vars = get_object_vars($this->params);

        $vars['sender'] = $this->params->senderName ?: $this->params->senderEmail ?: '';
        $vars['delegatee'] = $this->params->delegateName ?: $this->params->delegateEmail ?: '';

        $vars['body1'] = \trans("mail.itip-{$mode}-body", $vars);
        $vars['body2'] = '';

        if ($mode == 'reply' && !empty($this->params->partstat)) {
            $partstat = strtolower($this->params->partstat);
            $vars['body2'] = \trans("mail.itip-attendee-{$partstat}", $vars);
        }

        $this->view('emails.html.itip_notification')
            ->text('emails.plain.itip_notification')
            ->subject(\trans("mail.itip-{$mode}-subject", $vars))
            ->with(['vars' => $vars]);

        // TODO: Support aliases, i.e. use the email from the email message
        $this->to($this->params->user->email);

        // FIXME: Should we just send the message using Cockpit's "noreply" address?
        if (!empty($this->params->senderEmail)) {
            $this->from($this->params->senderEmail, $this->params->senderName);
        }

        return $this;
    }
}
