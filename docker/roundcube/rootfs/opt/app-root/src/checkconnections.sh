#!/bin/bash

set -e

#Check all connections that roundcube requires. Should probably be a php script.
#* imap
#* chwala
#
# External access
# file_api_url
# kolab_files_url
# kolab_addressbook_carddav_url
# calendar_caldav_url
# FIXME the telnet checks no longer seem to work (fails with remote connection closed on LOGOUT...)
#IMAP_HOST=$(./getconfig.php default_host)
#IMAP_PORT=$(./getconfig.php default_port)
#echo "IMAP : $IMAP_HOST:$IMAP_PORT"
#echo "a01 LOGOUT" | telnet $IMAP_HOST $IMAP_PORT | grep "Connected to imap"
#echo "IMAP is OK"

#MAIL_HOST=$(./getconfig.php smtp_server)
#MAIL_PORT=$(./getconfig.php smtp_port)
#echo "SMTP : $MAIL_HOST:$MAIL_PORT"
#echo "quit" | telnet $MAIL_HOST $MAIL_PORT | grep "Connected to postfix"
#echo "SMTP is OK"

#FIXME in newer mariadb-shell variants there is --dsn, but in older mysql client version there doesn't seem to be something like it
# MYSQL_DSN=$(./getconfig.php db_dsnw)
# echo "Mysql : $IMAP_HOST:$IMAP_PORT"
# mysql --batch 'describe table foobar?'
# echo "IMAP is OK"

URL=$(./getconfig.php fileapi_wopi_office)
echo "WOPI office: $URL"
curl -sD /dev/stderr "$URL/hosting/discovery" -k | grep "<wopi-discovery>"
echo "WOPI office is OK"

URL=$(./getconfig.php kolab_files_server_url)
if [[ $URL == "" ]]; then
    URL=$(./getconfig.php kolab_files_url)
fi
echo "Chwala url: $URL"
curl -sD /dev/stderr "$URL/api/" -k | grep "Invalid session"
echo "Chwala is OK"

if [[ "$(./getconfig.php fileapi_backend)" == "kolabfiles" ]]; then
    URL=$(./getconfig.php fileapi_kolabfiles_baseuri)
    echo "Kolabfiles $URL"
    # We expect a 401 if the api call exists in this location (even unauthenticated).
    curl -s -o /dev/null -w "%{http_code}" "${URL}/v4/fs" | grep "401"
    echo "Kolabfiles API is OK"
fi


if [[ "$(./getconfig.php kolab_addressbook_driver)" == "carddav" ]]; then
    # $config['kolab_addressbook_carddav_server'] = "https://" . ($_SERVER["HTTP_HOST"] ?? null) . "/dav";
    URL=$(./getconfig.php kolab_addressbook_carddav_server)
    echo "Carddav $URL"
    curl -sD /dev/stderr -H "Content-Type: application/xml" -X PROPFIND -H "Depth: infinity" --data '<d:propfind xmlns:d="DAV:" xmlns:cs="https://calendarserver.org/ns/"><d:prop><d:resourcetype /><d:displayname /></d:prop></d:propfind>' $URL -k | grep "405 Method Not Allowed"
    echo "Carddav is OK"

    #FIXME this is for external access, so we can't test this here
    #FIXME username/host/addressbook substitution
    # $config['kolab_addressbook_carddav_url'] = 'http://%h/dav/addressbooks/%u/%i';
    # URL=$(./getconfig.php kolab_addressbook_carddav_url)
    # echo "Carddav $URL"
    # curl -sD /dev/stderr -H "Content-Type: application/xml" -X PROPFIND -H "Depth: infinity" --data '<d:propfind xmlns:d="DAV:" xmlns:cs="https://calendarserver.org/ns/"><d:prop><d:resourcetype /><d:displayname /></d:prop></d:propfind>' $URL -k
    # echo "Carddav is OK"
fi


if [[ "$(./getconfig.php calendar_driver)" == "caldav" ]]; then
    #$config['calendar_caldav_server'] = "https://" . ($_SERVER["HTTP_HOST"] ?? null) . "/dav";
    URL=$(./getconfig.php calendar_caldav_server)
    echo "Caldav $URL"
    curl -sD /dev/stderr -H "Content-Type: application/xml" -X PROPFIND -H "Depth: infinity" --data '<d:propfind xmlns:d="DAV:" xmlns:cs="https://calendarserver.org/ns/"><d:prop><d:resourcetype /><d:displayname /></d:prop></d:propfind>' $URL -k | grep "405 Method Not Allowed"
    echo "Caldav is OK"

    #FIXME this is for external access, so we can't test this here
    #$config['calendar_caldav_url'] = 'http://%h/dav/calendars/%u/%i';
fi


if [[ "$(./getconfig.php tasklist_driver)" == "caldav" ]]; then
    #$config['calendar_caldav_server'] = "https://" . ($_SERVER["HTTP_HOST"] ?? null) . "/dav";
    URL=$(./getconfig.php tasklist_caldav_server)
    echo "Tasklist caldav $URL"
    curl -sD /dev/stderr -H "Content-Type: application/xml" -X PROPFIND -H "Depth: infinity" --data '<d:propfind xmlns:d="DAV:" xmlns:cs="https://calendarserver.org/ns/"><d:prop><d:resourcetype /><d:displayname /></d:prop></d:propfind>' $URL -k | grep "405 Method Not Allowed"
    echo "Tasklist caldav is OK"
fi


echo "All checks complete"
