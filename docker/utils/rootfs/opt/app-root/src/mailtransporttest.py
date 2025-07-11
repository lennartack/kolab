#!/bin/env python3
"""
    Send an email via SMTP and then look for it via IMAP.

    ./mailtransporttest.py --sender-username test1@kolab.org --sender-password foobar --sender-host smtp.kolabnow.com --recipient-username test2@kolab.org --recipient-password foobar --recipient-host imap.kolabnow.com --validate
"""
from datetime import datetime, timedelta
import argparse
import sys
import imaplib
import smtplib
import uuid
import time


mailtemplate = '''
MIME-Version: 1.0
Date: {date}
From: {sender}
To: {to}
Subject: {subject}
Message-ID: {messageid}
Content-Transfer-Encoding: 7bit
Content-Type: text/plain; charset=US-ASCII

{body}
'''.strip()

mailtemplateWithAttachment = '''
MIME-Version: 1.0
Content-Type: multipart/mixed;
 boundary="=_291b8e96564265636432c6d494e02322"
Date: {date}
From: {sender}
To: {to}
Subject: {subject}
Message-ID: {messageid}

--=_291b8e96564265636432c6d494e02322
Content-Type: multipart/alternative;
 boundary="=_ceff0fd19756f45ed1295ee2069ff8e0"

--=_ceff0fd19756f45ed1295ee2069ff8e0
Content-Transfer-Encoding: 7bit
Content-Type: text/plain; charset=US-ASCII

sdlkjsdjf
--=_ceff0fd19756f45ed1295ee2069ff8e0
Content-Transfer-Encoding: quoted-printable
Content-Type: text/html; charset=UTF-8

<html><head><meta http-equiv=3D"Content-Type" content=3D"text/html; charset=
=3DUTF-8" /></head><body style=3D'font-size: 10pt; font-family: Verdana,Gen=
eva,sans-serif'>
<p>sdlkjsdjf</p>

</body></html>

--=_ceff0fd19756f45ed1295ee2069ff8e0--

--=_291b8e96564265636432c6d494e02322
Content-Transfer-Encoding: base64
Content-Type: text/plain;
 name=xorg.conf
Content-Disposition: attachment;
 filename=xorg.conf;
 size=211

U2VjdGlvbiAiRGV2aWNlIgogICAgSWRlbnRpZmllciAgICAgIkRldmljZTAiCiAgICBEcml2ZXIg
{attachment}ICAgIEJvYXJkTmFtZSAgICAgICJOVlMgNDIwME0iCiAgICBPcHRpb24gIk5vTG9nbyIgInRydWUi
CiAgICBPcHRpb24gIlVzZUVESUQiICJ0cnVlIgpFbmRTZWN0aW9uCg==
--=_291b8e96564265636432c6d494e02322--
'''.strip()

mailtemplateWithInvitation = '''
MIME-Version: 1.0
Date: {date}
From: {sender}
To: {to}
Subject: {subject}
Message-ID: {messageid}
Content-Type: multipart/alternative;
 boundary="=_e826fa0b51105002516c94e15d877816"

--=_e826fa0b51105002516c94e15d877816
Content-Transfer-Encoding: quoted-printable
Content-Type: text/plain; charset=UTF-8;
 format=flowed

*{subject}*

When: 2025-07-02 3:30 - 4:00 (Europe/Zurich)

Invitees: {sender},
  {to}

foo

Please find attached an iCalendar file with all the event details which you=
=20
can import to your calendar application.

In case your email client doesn't support iTip requests you can use the=20
following link to either accept or decline this invitation:
https://kolabnow.com/apps/V0LNO8LkN2ZZwZTs/?_task=3Dcalendar&_action=3Datte=
nd&_t=3D187b0097af83e44fe178df0a5dd9b61568b2ae7c.YWRtaW5AbmVzdGxlLmtvbGFiLm=
lv.1a6264
--=_e826fa0b51105002516c94e15d877816
Content-Transfer-Encoding: 8bit
Content-Type: text/calendar; charset=UTF-8; method=REQUEST;
 name=event.ics

BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Roundcube 1.6-git//Sabre VObject 4.5.7//EN
CALSCALE:GREGORIAN
METHOD:REQUEST
BEGIN:VTIMEZONE
TZID:Europe/Zurich
BEGIN:STANDARD
DTSTART:20241027T010000
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
TZNAME:CET
END:STANDARD
BEGIN:STANDARD
DTSTART:20251026T010000
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
TZNAME:CET
END:STANDARD
BEGIN:DAYLIGHT
DTSTART:20250330T010000
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
TZNAME:CEST
END:DAYLIGHT
END:VTIMEZONE
BEGIN:VEVENT
UID:{uid}
DTSTAMP:{dtstamp}
CREATED:{dtstamp}
LAST-MODIFIED:{dtstamp}
DTSTART;TZID=Europe/Zurich:{dtstart}
DTEND;TZID=Europe/Zurich:{dtend}
SUMMARY:{subject}
DESCRIPTION:test
SEQUENCE:0
TRANSP:OPAQUE
ATTENDEE;CN={to};PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT;CUTYPE=INDIVID
 UAL;RSVP=TRUE:mailto:{to}
ORGANIZER;CN={sender}:mailto:{sender}
END:VEVENT
END:VCALENDAR

--=_e826fa0b51105002516c94e15d877816--
'''.strip()

RED='\033[31m'
GREEN='\033[32m'
RESET='\033[39m'

def print_error(msg):
    print(RED + f"=> ERROR: {msg}")
    print(RESET)  # and reset to default color

def print_success(msg):
    print(GREEN + f"=> {msg}")
    print(RESET)  # and reset to default color

class SendTest:
    def __init__(self, options):
        self.recipient_host = options.recipient_host
        self.recipient_port = options.recipient_port
        self.recipient_username = options.recipient_username
        self.recipient_password = options.recipient_password

        self.sender_host = options.sender_host
        self.sender_port = options.sender_port
        self.sender_username = options.sender_username
        self.sender_password = options.sender_password

        self.target_address = options.target_address
        self.from_address = options.from_address

        self.body = options.body
        self.verbose = options.verbose
        self.validate = options.validate
        self.bulk_send = options.bulk_send
        self.attachmentSize = options.attachmentSize
        self.invitation = options.invitation

        self.uuid = None
        self.subject = None

    def validate_message(self, message):
        import email.parser
        import email.policy
        msg = email.parser.BytesParser(policy=email.policy.default).parsebytes(message)
        if self.verbose:
            print(msg)

        email_domain = self.sender_username.split('@')[1]

        for header in msg.get_all('Received'):
            # Detect final delivery via lmtp
            if "lmtp" in header:
                sent = datetime.strptime(msg.get('Date'), "%a, %d %b %Y %H:%M:%S %z")
                received = datetime.strptime(header.split(';')[1].strip(), "%a, %d %b %Y %H:%M:%S %z")
                delay = (sent-received).total_seconds()
                print(f"Delivery delay: {delay}s")


        if msg['DKIM-Signature']:
            print("There is a DKIM-Signature.")

        # DKIM validation status
        # Authentication-Results: kolab.klab.cc (amavis); dkim=pass (2048-bit key)
        #  reason="pass (just generated, assumed good)" header.d=kolab.klab.cc
        # dkim=neutral is what we get when the public key is not available to validate
        for header in msg.get_all('Authentication-Results', ["No header available"]):
            if "dkim=pass" not in header and "dkim=neutral" not in header:
                print_error("Failed to validate Authentication-Results header:", header)
                return False
            if f"header.d={email_domain}" not in header and f"header.i=@{email_domain}" not in header:
                print_error("DKIM signature is not aligned", header)
                return False

        if msg['X-Spam-Flag'] and "NO" not in msg['X-Spam-Flag']:
            print_error("Test email is flagged as spam")
            print("Existing header: " + str(msg['X-Spam-Flag']))
            return False

        if "NO" not in (msg['X-Virus-Scanned'] or ""):
            print("Message was virus scanned: " + str(msg['X-Virus-Scanned']))

        if msg['Received-Greylist']:
            print("Message was greylisted: " + str(msg['Received-Greylist']))

        if msg['Received-SPF'] and "pass" not in msg['Received-SPF'].lower():
            print("SPF did not pass: " + str(msg['Received-SPF']))

        # Ensure SPF record matches a received line?
        # Suggest SPF record ip (sender ip)
        # Validate DKIM-Signature according to DNS entry
        # These could all be statistics for prometheus

        return True

    def check_for_mail(self):
        print(f"Checking for uuid {self.uuid}")
        imap = imaplib.IMAP4_SSL(host=self.recipient_host, port=self.recipient_port)
        if self.verbose:
            imap.debug = 4
        imap.login(self.recipient_username, self.recipient_password)
        imap.select("INBOX")
        # FIXME This seems to find emails that are not there
        if self.body:
            typ, data = imap.search(None, 'BODY', self.uuid)
        else:
            typ, data = imap.search(None, 'SUBJECT', self.uuid)


        for num in data[0].split():
            print(f"Found the mail with uid {num}")

            if self.validate:
                typ, data = imap.fetch(num, "(RFC822)")
                message = data[0][1]
                if not self.validate_message(message):
                    print_error("Failed to validate the message.")
                    print(message.decode())
                    sys.exit(1)


            imap.store(num, '+FLAGS', '\\Deleted')
            imap.expunge()
            return True
        return False

    def get_message(self, from_address, to):
        self.uuid = str(uuid.uuid4())
        self.subject = f"Delivery Check {self.uuid}"
        dtstamp = datetime.utcnow()
        if self.invitation:
            start = dtstamp
            end = start + timedelta(seconds=3600)
            return mailtemplateWithInvitation.format(
                messageid="<{}@deliverycheck.org>".format(self.uuid),
                subject=self.subject,
                sender=from_address,
                to=to,
                date=dtstamp.strftime("%a, %d %b %Y %H:%M:%S %z"),
                dtstamp=dtstamp.strftime("%Y%m%dT%H%M%SZ"),
                dtstart=start.strftime("%Y%m%dT%H%M%S"),
                dtend=end.strftime("%Y%m%dT%H%M%S"),
                uid=self.uuid,
                body=self.body,
            )

        if self.attachmentSize:
            # 13158 is roughly 1 MB
            attachmentMultiplier = 13158 * self.attachmentSize
            return mailtemplateWithAttachment.format(
                messageid="<{}@deliverycheck.org>".format(self.uuid),
                subject=self.subject,
                sender=from_address,
                to=to,
                date=dtstamp.strftime("%a, %d %b %Y %H:%M:%S %z"),
                body=self.body,
                attachment='ICAgIEJvYXJkTmFtZSAgICAgICJOVlMgNDIwME0iCiAgICBPcHRpb24gIk5vTG9nbyIgInRydWUi\n' * attachmentMultiplier
            )

        return mailtemplate.format(
            messageid="<{}@deliverycheck.org>".format(self.uuid),
            subject=self.subject,
            sender=from_address,
            to=to,
            date=dtstamp.strftime("%a, %d %b %Y %H:%M:%S %z"),
            body=self.body,
        )

    def send_mail_loop(self, smtp):
        if self.target_address:
            to = self.target_address
        else:
            to = self.recipient_username

        if self.from_address is not None:
            from_address = self.from_address
        else:
            from_address = self.sender_username

        count = 1 if not self.bulk_send else self.bulk_send
        print(f"Sending {count} email to {to}")

        for _ in range(count):
            smtp.sendmail(from_address, to, self.get_message(from_address, to))
            print(f"Email with uuid {self.uuid} sent")

    def send_mail(self, starttls, smtp):
        if smtp:
            with smtplib.SMTP(host=self.sender_host, port=self.sender_port or 25) as smtp:
                if self.verbose:
                    smtp.set_debuglevel(2)
                smtp.ehlo()
                smtp.noop()
                self.send_mail_loop(smtp)

        elif starttls:
            with smtplib.SMTP(host=self.sender_host, port=self.sender_port or 587) as smtp:
                if self.verbose:
                    smtp.set_debuglevel(2)
                smtp.starttls()
                smtp.ehlo()
                smtp.login(self.sender_username, self.sender_password)
                smtp.noop()
                self.send_mail_loop(smtp)
        else:
            with smtplib.SMTP_SSL(host=self.sender_host, port=self.sender_port or 465) as smtp:
                if self.verbose:
                    smtp.set_debuglevel(2)
                smtp.login(self.sender_username, self.sender_password)
                smtp.noop()
                self.send_mail_loop(smtp)


parser = argparse.ArgumentParser(description='Mail transport tests.')
parser.add_argument('--sender-username', help='The SMTP sender username')
parser.add_argument('--sender-password', help='The SMTP sender password')
parser.add_argument('--sender-host', help='The SMTP sender host')
parser.add_argument('--sender-port', help='The SMTP sender port (defaults to 465/587)')
parser.add_argument('--recipient-username', help='The IMAP recipient username')
parser.add_argument('--recipient-password', help='The IMAP recipient password')
parser.add_argument('--recipient-host', help='The IMAP recipient host')
parser.add_argument('--recipient-port', help='The IMAP recipient port', default=993)
parser.add_argument('--timeout', help='Timeout in minutes', type=int, default=10)
parser.add_argument("--starttls", action='store_true', help="Use SMTP starttls over port 587")
parser.add_argument("--smtp", action='store_true', help="Use SMTP over port 25")
parser.add_argument("--verbose", action='store_true', help="Verbose mode")
parser.add_argument("--from-address", help="Source address instead of the sender username")
parser.add_argument("--target-address", help="Target address instead of the recipient username (can be specified multiple times)", action='append')
parser.add_argument("--body", help="Body text to include")
parser.add_argument("--validate", action='store_true', help="Validate the received message")
parser.add_argument('--bulk-send', help='Bulk send email, then exit', type=int, default=0)
parser.add_argument('--invitation', action='store_true', help='Send an invitation')
parser.add_argument('--attachmentSize', help='in MB', type=int, default=0)

args = parser.parse_args()

obj = SendTest(args)
obj.send_mail(args.starttls, args.smtp)

if args.bulk_send:
    sys.exit(0)

timeout = 10

for i in range(1, round(args.timeout * 60 / timeout) + 1):
    if obj.check_for_mail():
        print_success("Success!")
        # TODO print statistics? Push statistics directly someplace?
        sys.exit(0)
    print(f"waiting for {timeout}")
    time.sleep(timeout)

# TODO print statistics? Push statistics directly someplace?

print_error("Failed to find the mail")
sys.exit(1)
