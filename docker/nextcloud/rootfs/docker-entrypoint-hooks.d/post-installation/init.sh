#!/bin/bash

cd /var/www/html
./occ config:system:set allow_local_remote_servers --value=true
./occ app:install user_oidc
./occ user_oidc:provider "Kolab" \
    --clientid="$NEXTCLOUD_OAUTH_CLIENT_ID" \
    --clientsecret="$NEXTCLOUD_OAUTH_CLIENT_SECRET" \
    --discoveryuri="http://$APP_DOMAIN/.well-known/openid-configuration" \
    --unique-uid=0 \
    --scope "openid email"
