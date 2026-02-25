#!/bin/env python3

"""
kolabendpointtester.py
    --host apps.kolabnow.com
    --user user@kolab.org
    --password Secret
    --dav https://apps.kolabnow.com
    --fb https://apps.kolabnow.com/calendars/user@kolab.org/6f552d35-95c4-41f6-a7d2-cfd02dd867db
"""

import sys
import traceback
import socket
import ssl
import argparse
from base64 import b64encode
import http.client
import urllib.parse
from imaplib import IMAP4
from imaplib import IMAP4_SSL
from smtplib import SMTP
from smtplib import SMTP_SSL
import dns.resolver
import re

# print('\033[31m' + 'some red text')

RED='\033[31m'
GREEN='\033[32m'
RESET='\033[39m'

SSLNOVERIFY = True

def print_error(msg):
    print(RED + f"=> ERROR: {msg}")
    print(RESET)  # and reset to default color

def print_success(msg):
    print(GREEN + f"=> {msg}")
    print(RESET)  # and reset to default color


def print_assertion_failure():
    """
        Print an error message about a failed assertion
    """
    _, _, trace = sys.exc_info()
    tb_info = traceback.extract_tb(trace)
    _filename, line, _func, text = tb_info[-1]
    print(f"  ERROR assertion on line {line} failed on {text}")


def http_request(url, method, params=None, headers=None, body=None, verbose=False):
    """
        Perform an HTTP request.
    """

    parsed_url = urllib.parse.urlparse(url)
    # print("Connecting to ", parsed_url.netloc)
    if url.startswith('https://'):
        conn = http.client.HTTPSConnection(parsed_url.netloc, 443, context = (ssl._create_unverified_context() if SSLNOVERIFY else None))
    else:
        conn = http.client.HTTPConnection(parsed_url.netloc, 80)

    if verbose:
        conn.set_debuglevel(9)
    conn.connect()

    if params is None:
        params = {}

    if headers is None:
        headers = {
            "Content-Type": "application/x-www-form-urlencoded; charset=utf-8"
        }

    if body is None:
        body = urllib.parse.urlencode(params)

    # Assemble a relative url
    url = urllib.parse.urlunsplit(["", "", parsed_url.path, parsed_url.query, parsed_url.fragment])
    print(f"Requesting {url} From {parsed_url.netloc} Using {method}")
    conn.request(method, url, body, headers)
    response = conn.getresponse()

    # Handle redirects
    if response.status in (301, 302,):
        print("Following redirect ", response.getheader('location', ''))
        return http_request(
            urllib.parse.urljoin(url, response.getheader('location', '')),
            method,
            params,
            headers,
            body,
            verbose)

    return response


def basic_auth_headers(username, password):
    user_and_pass = b64encode(
        f"{username}:{password}".encode("ascii")
    ).decode("ascii")

    return {
        "Authorization": "Basic {}".format(user_and_pass)
    }

def try_get(name, url, verbose, headers = None, body = None):
    try:
        response = http_request(
            url,
            "GET",
            None,
            headers,
            body,
            verbose
        )
        success = response.status == 200
    except http.client.RemoteDisconnected:
        print("Remote disconnected")
        print_error(f"{name} is not available")
        return False

    if not success:
        print_error(f"{name} is not available")

    if verbose or not success:
        print("  ", "Status", response.status)
        print("  ", response.read().decode())

    return success

def test_caldav_redirect(host, username, password, verbose):
    return discover_principal("https://" + host + "/.well-known/caldav", username, password, verbose)

def discover_principal(url, username, password, verbose):
    body = '<d:propfind xmlns:d="DAV:" xmlns:cs="https://calendarserver.org/ns/"><d:prop><d:current-user-principal /></d:prop></d:propfind>'

    headers = {
        "Content-Type": "application/xml; charset=utf-8",
        "Depth": "0",
        **basic_auth_headers(username, password)
    }

    try:
        response = http_request(
            f"{url}",
            "PROPFIND",
            None,
            headers,
            body,
            verbose
        )
    except http.client.RemoteDisconnected:
        print_error(f"Caldav is not available at {url} (Remote disconnected)")
        return False

    success = response.status == 207
    if not success:
        print_error(f"Caldav is not available at {url} ({response.status} != 207)")

    if verbose or not success:
        print("  ", "Status", response.status)
        print("  ", response.read().decode())

    return success


def test_freebusy_authenticated(url, username, password, verbose = False):
    # Request our own freebusy authenticated
    return try_get("Authenticated Freebusy", f"{url}/{username}.ifb", verbose, headers = basic_auth_headers(username, password))


def test_freebusy_unauthenticated(url, username, password, verbose = False):
    return try_get("Unauthenticated Freebusy", f"{url}/{username}.ifb", verbose)


def test_autoconfig(host, username, password, verbose = False):
    if not try_get("Autoconf .well-known", f"https://{host}/.well-known/autoconfig/mail/config-v1.1.xml?emailaddress={username}", verbose):
        return False
    if not try_get("Autoconf /mail", f"https://{host}/mail/config-v1.1.xml?emailaddress={username}", verbose):
        return False
    return True

# TODO
# def test_007_well_known_outlook():
#     body = '''<Autodiscover \
# xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/requestschema/2006">
#     <Request>
#       <EMailAddress>admin@example.local</EMailAddress>
#       <AcceptableResponseSchema>
#          http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a
#        </AcceptableResponseSchema>
#     </Request>
# </Autodiscover>'''

#     headers = {
#         "Content-Type": "text/xml; charset=utf-8"
#     }
#     response = http_post(
#         "https://kolab-vanilla.{}.local/autodiscover/autodiscover.xml".format(hostname),
#         None,
#         headers,
#         body
#     )
#     assert response.status == 200
#     data = response.read()
#     decoded = codecs.decode(data)
#     # Sanity check of the data
#     assert '<Server>example.local</Server>' in decoded
#     assert "admin@example.local" in decoded

#     # Ensure the alternative urls also work
#     assert http_post(
#         "https://kolab-vanilla.{}.local/Autodiscover/Autodiscover.xml".format(hostname),
#         None,
#         headers,
#         body
#     ).status == 200

#     assert http_post(
#         "https://kolab-vanilla.{}.local/AutoDiscover/AutoDiscover.xml".format(hostname),
#         None,
#         headers,
#         body
#     ).status == 200


def test_autodiscover_activesync(host, activesynchost, username, password, verbose = False):
    """
    We expect something along the lines of

    <?xml version="1.0" encoding="UTF-8"?>
    <Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">
    <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006">
        <User>
        <DisplayName>User Name</DisplayName>
        <EMailAddress>user@example.com</EMailAddress>
        </User>
        <Action>
        <Settings>
            <Server>
            <Type>MobileSync</Type>
            <Url>https://kolab.example.com/Microsoft-Server-ActiveSync</Url>
            <Name>https://kolab.example.com/Microsoft-Server-ActiveSync</Name>
            </Server>
        </Settings>
        </Action>
    </Response>
    </Autodiscover>
    """

    body = f'''<Autodiscover \
xmlns="http://schemas.microsoft.com/exchange/autodiscover/mobilesync/requestschema/2006">
    <Request>
      <EMailAddress>{username}</EMailAddress>
      <AcceptableResponseSchema>
         http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006
       </AcceptableResponseSchema>
    </Request>
</Autodiscover>'''

    headers = {
        "Content-Type": "text/xml; charset=utf-8",
        **basic_auth_headers(username, password)
    }

    try:
        response = http_request(
            f"https://{host}/autodiscover/autodiscover.xml",
            "POST",
            None,
            headers,
            body,
            verbose
        )
    except http.client.RemoteDisconnected:
        print("Remote disconnected")
        print_error("Activesync autodiscover is not available")
        return False

    success = response.status == 200
    data = response.read().decode()
    if success:
        try:
            # Sanity check of the data
            assert "<Type>MobileSync</Type>" in data
            assert f"<Url>https://{activesynchost}/Microsoft-Server-ActiveSync</Url>" in data
            assert username in data
        except AssertionError:
            print(data)
            print_assertion_failure()
            success = False

    if not success:
        print_error("Activesync autodiscover is not available")

    if verbose or not success:
        print("  ", "Status", response.status)
        print("  ", data)

    return success


def test_activesync(host, username, password, verbose = False):
    headers = {
        "Host": host,
        **basic_auth_headers(username, password)
    }

    try:
        response = http_request(
            f"https://{host}/Microsoft-Server-ActiveSync",
            "OPTIONS",
            None,
            headers,
            None,
            verbose
        )
    except http.client.RemoteDisconnected:
        print("Remote disconnected")
        print_error("Activesync is not available")
        return False

    success = response.status == 200
    data = response.read().decode()
    if success:
        try:
            assert response.getheader('MS-Server-ActiveSync', '')
            assert '14.1' in response.getheader('MS-ASProtocolVersions', '')
            assert 'FolderSync' in response.getheader('MS-ASProtocolCommands', '')
        except AssertionError:
            print_assertion_failure()
            success = False

    if not success:
        print_error("Activesync is not available")

    if verbose or not success:
        print("  ", "Status", response.status)
        print("  ", data)

    return success


def test_dns(host, verbose = False):
    success = True
    try:
        answers = dns.resolver.resolve(host, 'MX')
        for rdata in answers:
            print('  MX Host', rdata.exchange, 'has preference', rdata.preference)
    except dns.resolver.NXDOMAIN:
        success = False
        print("  ERROR on MX record")
    except dns.resolver.NoAnswer:
        success = False
        print("  ERROR on MX record")

    if not test_reverse_lookup(host, verbose):
        success = False
        print("  ERROR on Reverse lookup")

    try:
        answers = dns.resolver.resolve(f"autodiscover.{host}", 'CNAME')
        for rdata in answers:
            print('  autodiscover CNAME', rdata.target)
    except dns.resolver.NXDOMAIN:
        success = False
        print(f"  ERROR on autodiscover.{host} CNAME entry")
    except dns.resolver.NoAnswer:
        success = False
        print(f"  ERROR on autodiscover.{host} CNAME entry")

    srv_records = [
        f"_autodiscover._tcp.{host}",
        f"_caldav._tcp.{host}",
        f"_caldavs._tcp.{host}",
        f"_carddav._tcp.{host}",
        f"_carddavs._tcp.{host}",
        f"_imap._tcp.{host}",
        f"_imaps._tcp.{host}",
        f"_sieve._tcp.{host}",
        f"_submission._tcp.{host}",
        f"_webdav._tcp.{host}",
        f"_webdavs._tcp.{host}",
    ]

    for record in srv_records:
        try:
            answers = dns.resolver.resolve(record, 'SRV')
            for rdata in answers:
                print("  ", record, rdata.target)
        except dns.resolver.NXDOMAIN:
            success = False
            print("  ERROR on record", record)
        except dns.resolver.NoAnswer:
            success = False
            print("  ERROR on record", record)

    if not success:
        print_error(f"Dns entries on {host} not available")

    return success


def test_reverse_lookup(host, verbose=False):
    success = True
    try:
        for address in dns.resolver.resolve_name(host).addresses():
            print(f"  {host} resolves to", address)
            reverseLookupResult = list(map(lambda x: str(x), dns.resolver.resolve_address(address)))
            print(f"  Reverse lookup result", reverseLookupResult)
            if f"{host}." not in reverseLookupResult:
                success = False
                print('  Reverse lookup failed (Missing PTR entry?)')
    except dns.resolver.NXDOMAIN:
        success = False
        print("  ERROR on dns resolution")
    except dns.resolver.NoAnswer:
        success = False
        print("  ERROR on dns resolution")

    return success


def test_email_dns(host, verbose = False):
    success = True

    if not test_reverse_lookup(host, verbose):
        success = False
        print("  ERROR on Reverse lookup")

    srv_records = [
        f"_autodiscover._tcp.{host}"
    ]

    for record in srv_records:
        try:
            answers = dns.resolver.resolve(record, 'SRV')
            for rdata in answers:
                print("  ", record, rdata.target)
        except dns.resolver.NXDOMAIN:
            success = False
            print("  ERROR on record", record)
        except dns.resolver.NoAnswer:
            success = False
            print("  ERROR on record", record)

    if not success:
        print_error(f"Dns entries on {host} not available")

    return success

def test_dmarc_dns(host, verbose = False):
    success = True

    try:
        answers = dns.resolver.resolve(f"_dmarc.{host}", 'TXT')
        for rdata in answers:
            print("  _dmarc TXT", rdata)
    except dns.resolver.NXDOMAIN:
        success = False
        print("  ERROR on _dmarc TXT")
    except dns.resolver.NoAnswer:
        success = False
        print("  ERROR on _dmarc TXT")

    if not success:
        print_error(f"DMARC dns  entries on {host} not available")

    return success

def validate_spf_record(rdata):
    # Something like "v=spf1 mx a:kolab.klab.cc mx:kolab.klab.cc -all"
    data = str(rdata)
    if not data.startswith('"v=spf1'):
        return False

    if not data.endswith('-all"'):
        return False

    return True

def test_spf_dns(host, verbose = False):
    success = False

    try:
        answers = dns.resolver.resolve(f"{host}", 'TXT')
        for rdata in answers:
            if validate_spf_record(rdata):
                print("  SPF TXT", rdata)
                success = True
                break
            else:
                print(f"  ERROR while validating spf record {data}")

    except dns.resolver.NXDOMAIN:
        success = False
        print("  ERROR on SPF TXT")
    except dns.resolver.NoAnswer:
        success = False
        print("  ERROR on SPF TXT")

    if not success:
        print_error(f"SPF dns entry on {host} not available")

    return success

def validate_dkim_record(rdata):
    data = str(rdata)
    # Re-assemble the quoted string
    data = "".join(re.findall('"([^"]*)"', data))
    print(data)
    if not data.startswith("v=DKIM1;"):
        return False
    return True

def test_dkim_dns(host, selector, verbose = False):
    success = False

    try:
        answers = dns.resolver.resolve(f"{selector}._domainkey.{host}", 'TXT')
        for rdata in answers:
            print(f"  DKIM {selector}", rdata)
            if validate_dkim_record(rdata):
                success = True
            else:
                print(f"  ERROR while validating dkim key {data}")
    except dns.resolver.NXDOMAIN:
        success = False
        print("  ERROR on DKIM TXT")
    except dns.resolver.NoAnswer:
        success = False
        print("  ERROR on DKIM TXT")

    if not success:
        print_error(f"DKIM dns entries on {host} not available")

    return success


def test_mta_sts(host, verbose = False):
    success = False

    # lookup https://mta-sts.kolabnow.com/.well-known/mta-sts.txt
    try:
        answers = dns.resolver.resolve(f"_mta_sts.{host}", 'TXT')
        for rdata in answers:
            print("  MTA-STS ", rdata)
            if rdata.startswith("v=STSv1;"):
                success = True
            else:
                print(f"  ERROR while validating dkim key {rdata}")
    except dns.resolver.NXDOMAIN:
        success = False
        print("  ERROR on MTA-STS TXT")
    except dns.resolver.NoAnswer:
        success = False
        print("  ERROR on MTA-STS TXT")

    if not try_get(f"https://mta-sts.{host}/.well-known/mta-sts.txt"):
        success = False
        print("  Failed to get the mta-sts policy")
    # TODO validate policy and potentially cross check with spf policy?

    if not success:
        print_error(f"MTA-STS dns entries on {host} not available")

    return success


def test_imap(host, user, password, verbose):
    success = True

    hosts = [
        (host, 993, True, False),
        # (host, 143, False, True),
    ]

    for hosttuple in hosts:
        if verbose:
            print("Connecting to ", hosttuple)

        host, port, usessl, starttls = hosttuple

        try:
            if usessl:
                imap = IMAP4_SSL(host=host, port=port)
            else:
                imap = IMAP4(host=host, port=port)

            if starttls:
                imap.starttls()

            imap.login(user, password)

            status, list_response = imap.list()
            assert status == 'OK', "LIST failed"

            inbox_found = False
            for folder in list_response:
                # Folder can be None if there is no folder
                if folder and 'INBOX' in folder.decode('utf-8'):
                    inbox_found = True

            assert inbox_found, "Failed to find INBOX"

        except AssertionError as err:
            print("  ERROR on peer", hosttuple, err)
            success = False

        except Exception as err:  # pylint: disable=broad-except
            print("  ERROR on peer", hosttuple, err)
            success = False

        if not success:
            print_error("IMAP failed")

    return success


def test_smtp(host, user, password, verbose, testSMTPS=True):
    success = True

    hosts = []
    if testSMTPS:
        hosts.append((host, 465, True, False))
    hosts.append((host, 587, False, True))

    for hosttuple in hosts:
        if verbose:
            print("Connecting to ", hosttuple)

        host, port, usessl, starttls = hosttuple

        try:
            if usessl:
                smtp = SMTP_SSL(host=host, port=port)
            else:
                smtp = SMTP(host=host, port=port)

            if starttls:
                smtp.starttls()

            # check we have an open socket
            assert smtp.sock

            # run a no-operation, which is basically a server-side pass-through
            status, _response = smtp.noop()
            assert status == 250

            status, _response = smtp.login(user, password)
            assert status == 235

            status, _response = smtp.quit()
            assert status == 221

        except AssertionError as err:
            print("  ERROR on peer", hosttuple, err)
            success = False

        except Exception as err:  # pylint: disable=broad-except
            print("  ERROR on peer", hosttuple, err)
            success = False

    if not success:
        print_error("SMTP failed")

    return success


def test_certificates(host, davhost, imaphost, verbose):
    success = True
    hosts = [
        (host, 443),
    ]

    if davhost:
        hosts.append((urllib.parse.urlparse(davhost).netloc, 443))
    if imaphost:
        hosts.append((imaphost, 993))
        hosts.append((imaphost, 465))

    context = ssl.create_default_context()

    for hosttuple in hosts:
        hostname, _port = hosttuple
        try:
            conn = context.wrap_socket(socket.socket(socket.AF_INET), server_hostname=hostname)
            conn.connect(hosttuple)
            cert = conn.getpeercert()
            if verbose:
                print(f"Certificate for {hosttuple}: {cert}")
        except OSError as err:
            print("  ERROR on peer", hosttuple, err)
            success = False

    if not success:
        print_error("Not all certificates are valid")

    return success


def test_meet(host, verbose):
    headers = {
        "Host": host
    }

    try:
        response = http_request(
            f"https://{host}/meetmedia/signaling",
            "OPTIONS",
            None,
            headers,
            None,
            verbose
        )
    except http.client.RemoteDisconnected:
        print("Remote disconnected")
        print_error("Activesync is not available")
        return False

    success = response.status == 200
    data = response.read().decode()
    if success:
        try:
            assert "Transport unknown" in data
        except AssertionError:
            print_assertion_failure()
            success = False

    if not success:
        print_error("Meet signaling is not available")

    if verbose or not success:
        print("  ", "Status", response.status)
        print("  ", data)

    return success


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", help="Host")
    parser.add_argument("--username", help="Username")
    parser.add_argument("--password", help="User password")
    parser.add_argument("--imap", help="IMAP URI")
    parser.add_argument("--smtp", help="SMTP URI")
    parser.add_argument("--nosmtps", action='store_true', help="Boolean to disable the smtps check")
    parser.add_argument("--dav", help="DAV URI")
    parser.add_argument("--meet", help="MEET URI")
    parser.add_argument("--autoconfig", help="Check autoconfig")
    parser.add_argument("--dns", action='store_true', help="Check dns")
    parser.add_argument("--dkim", help="Check DKIM dns record")
    parser.add_argument("--mtasts", help="Check mta-sts")
    parser.add_argument("--activesync", help="ActiveSync URI")
    parser.add_argument("--certificates", action='store_true', help="Check Certificates")
    parser.add_argument("--fb", help="Freebusy url as displayed in roundcube")
    parser.add_argument("--verbose", action='store_true', help="Verbose output")
    parser.add_argument("--default", action='store_true', help="Standard checks with only username and password")
    options = parser.parse_args()

    error = False

    # Maybe structure it like this.
    # Then print report, and upload results to prometheus
    # [testName, testEnabled, testLambda] => [testName, testResult]

    if options.default:
        options.host = options.username.split('@')[1]
        options.dav = "https://" + options.host + "/.well-known/caldav"
        options.imap = options.host
        options.smtp = options.host
        options.activesync = options.host
        options.certificates = True

    if options.dav:
        if discover_principal(options.dav, options.username, options.password, options.verbose):
            print_success("Caldav is available")
        else:
            error = True

        if options.host:
            if test_caldav_redirect(options.host, options.username, options.password, options.verbose):
                print_success("Caldav on .well-known/caldav is available")
                # Kolabnow doesn't support this atm (it offers the redirect on apps.kolabnow.com),
                # so we ignore the error for now

    if options.autoconfig:
        if test_autoconfig(options.host, options.username, options.password, options.verbose):
            print_success("Autoconf available")
        else:
            error = True

    if options.activesync:
        if options.autoconfig:
            if test_autodiscover_activesync(options.host, options.activesync, options.username, options.password, options.verbose):
                print_success("Activesync Autodsicovery available")
                # Kolabnow doesn't support this, so we ignore the error for now

        if test_activesync(options.activesync, options.username, options.password, options.verbose):
            print_success("Activesync available")
        else:
            error = True

    if options.fb:
        if test_freebusy_authenticated(options.fb, options.username, options.password, options.verbose):
            print_success("Authenticated Freebusy is available")
        else:
            error = True

        # We rely on the activesync test to have generated the token for unauthenticated access.
        if test_freebusy_unauthenticated(options.fb, options.username, options.password, options.verbose):
            print_success("Unauthenticated Freebusy is available")
        else:
            error = True

    if options.dns:
        if test_dns(options.host, options.verbose):
            print_success(f"DNS entries on {options.host} available")
        else:
            error = True

        if test_dmarc_dns(options.host, options.verbose):
            print_success(f"DMARC DNS entries on {options.host} available")
        else:
            error = True

        if test_spf_dns(options.host, options.verbose):
            print_success(f"SPF DNS entries on {options.host} available")
        else:
            error = True

        userhost = options.username.split('@')[1]
        if test_email_dns(userhost, options.verbose):
            print_success(f"User DNS entries on {userhost} available")
        else:
            error = True

    if options.dkim:
        if test_dkim_dns(options.host, options.dkim, options.verbose):
            print_success(f"DKIM DNS entries on {options.host} available")
        else:
            error = True

    if options.mtasts:
        if test_mta_sts(options.host, options.verbose):
            print_success(f"MTA-STS on {options.host} available")
        else:
            error = True

    if options.certificates:
        if test_certificates(options.host, options.dav, options.imap, options.verbose):
            print_success("All certificates are valid")
        else:
            error = True

    if options.imap:
        if test_imap(options.imap, options.username, options.password, options.verbose):
            print_success("IMAP is available")
        else:
            error = True

    if options.smtp:
        if test_smtp(options.smtp, options.username, options.password, options.verbose, not options.nosmtps):
            print_success("SMTP is available")
        else:
            error = True

    if options.meet:
        if test_meet(options.meet, options.verbose):
            print_success("Meet is available")
        else:
            error = True

    # Push result to prometheus

    if error:
        print_error("At least one check failed")
        sys.exit(1)

if __name__ == "__main__":
    main()
