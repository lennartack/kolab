#!/bin/bash

set -e

if [[ -f ${SSL_CERTIFICATE} ]]; then
cat ${SSL_CERTIFICATE} ${SSL_CERTIFICATE_FULLCHAIN} ${SSL_CERTIFICATE_KEY} > /etc/pki/tls/private/postfix.pem
chown postfix:mail /etc/pki/tls/private/postfix.pem
chmod 655 /etc/pki/tls/private/postfix.pem
fi


sed -i -r \
    -e "s|LMTP_DESTINATION|${LMTP_DESTINATION:?"env required"}|g" \
    -e "s|APP_DOMAIN|${APP_DOMAIN:?"env required"}|g" \
    -e "s|MYNETWORKS|${MYNETWORKS:?"env required"}|g" \
    -e "s|AMAVIS_HOST|${AMAVIS_HOST:?"env required"}|g" \
    -e "s|MESSAGE_SIZE_LIMIT|${MESSAGE_SIZE_LIMIT:?"env required"}|g" \
    /etc/postfix/main.cf

mkdir /var/log/kolab
touch /var/log/kolab/postfix-content-filter.log
#touch /var/log/kolab/postfix-policy-greylist.log
touch /var/log/kolab/postfix-policy-submission.log
touch /var/log/kolab/postfix-policy-reception.log
touch /var/log/kolab/postfix-policy-spf.log
chmod -R 777 /var/log/kolab

chown -R postfix:mail /var/lib/postfix
chown -R postfix:mail /var/spool/postfix
/usr/sbin/postfix set-permissions

sed -i -r \
    -e "s|APP_SERVICES_DOMAIN|$APP_SERVICES_DOMAIN|g" \
    -e "s|SERVICES_PORT|$SERVICES_PORT|g" \
    /etc/saslauthd.conf

/usr/sbin/saslauthd -m /run/saslauthd -a httpform -d &

# If host mounting /var/spool/postfix, we need to delete old pid file before
# starting services
rm -f /var/spool/postfix/pid/master.pid

/usr/libexec/postfix/aliasesdb
/usr/libexec/postfix/chroot-update

sed -i -r \
    -e "s|MYNETWORKS|${MYNETWORKS:?"env requried"}|g" \
    -e "s|AMAVIS_HOST|${AMAVIS_HOST:?"env requried"}|g" \
    /etc/postfix/master.cf


if [ "$WITH_CONTENTFILTER" != "true" ]; then
    echo "Disabling kolab content filter"
    sed -i -r \
        -e "s|content_filter=policy_mailfilter:dummy|content_filter=|g" \
        /etc/postfix/master.cf
fi

if [ "$WITH_PROXY_PROTOCOL" == "true" ]; then
    sed -i -r \
        -e "s|smtpd_upstream_proxy_protocol=|smtpd_upstream_proxy_protocol=haproxy|g" \
        /etc/postfix/master.cf
fi

if [ "$BLOCK_OUTGOING_EMAILS" == "true" ]; then
    echo "default_transport = error:No outside emails." >> /etc/postfix/main.cf
fi

if [ "$HOLD_INCOMING_EMAILS" == "true" ]; then
    sed -i -r \
        -e "s|#check_recipient_access static:HOLD|check_recipient_access static:HOLD|g" \
        /etc/postfix/main.cf
fi

if [ "$RELAY_HOST" != "" ]; then
    sed -i -r \
        -e "s|#relayhost=|relayhost=$RELAY_HOST|g" \
        /etc/postfix/main.cf
fi

sed -i -r \
    -e "s|SERVICES_HOST|http://$APP_SERVICES_DOMAIN:$SERVICES_PORT|g" \
    /usr/libexec/postfix/kolab_policy*

sed -i -r \
    -e "s|SERVICES_HOST|http://$APP_SERVICES_DOMAIN:$SERVICES_PORT|g" \
    /usr/libexec/postfix/kolab_contentfilter*

sed -i -r \
    -e "s|DB_HOST|${DB_HOST:?"env required"}|g" \
    -e "s|DB_USERNAME|${DB_USERNAME:?"env required"}|g" \
    -e "s|DB_PASSWORD|${DB_PASSWORD:?"env required"}|g" \
    -e "s|DB_DATABASE|${DB_DATABASE:?"env required"}|g" \
    /etc/postfix/sql/*

# echo "/$APP_DOMAIN/              lmtp:$LMTP_DESTINATION" >> /etc/postfix/transport
# postmap /etc/postfix/transport
postmap /etc/postfix/sender_access

/usr/sbin/postfix check
exec /usr/sbin/postfix -c /etc/postfix start-fg
