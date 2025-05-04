#!/bin/bash

# Build the nginx conf
rm /etc/nginx/nginx.conf
cat <<'EOF' > /etc/nginx/nginx.conf
worker_processes auto;
error_log stderr info;
pid /run/nginx.pid;

# Load dynamic modules. See /usr/share/doc/nginx/README.dynamic.
include /usr/share/nginx/modules/*.conf;

events {
    worker_connections 1024;
}

http {
    log_format  main  '$remote_addr - $remote_user [$time_local] "$request" '
                      '$status $body_bytes_sent "$http_referer" '
                      '"$http_user_agent" "$http_x_forwarded_for"';

    access_log  /dev/stdout  main;

    sendfile            on;
    tcp_nopush          on;
    tcp_nodelay         on;
    keepalive_timeout   65;
    types_hash_max_size 2048;

    include             /etc/nginx/mime.types;
    default_type        application/octet-stream;

    map $http_upgrade $connection_upgrade {
        default upgrade;
        '' close;
    }

EOF

cat <<EOF >> /etc/nginx/nginx.conf
    # FIXME this requires a resolver to resolve domainnames
    resolver 192.168.1.1 valid=10s;

    map \$dav_backend \$backend_url {
        default $DAV_BACKEND$DAV_PATH;
        alternative $DAV_ALTERNATIVE_BACKEND$DAV_ALTERNATIVE_PATH;
    }

    # Load modular configuration files from the /etc/nginx/conf.d directory.
    # See http://nginx.org/en/docs/ngx_core_module.html#include
    # for more information.
    include /etc/nginx/conf.d/*.conf;

    server {
        listen 6080 default_server;
        listen 6443 default_server ssl;
        listen [::]:6443 ssl ipv6only=on;

        ssl_certificate $SSL_CERTIFICATE;
        ssl_certificate_key $SSL_CERTIFICATE_KEY;

        server_name  $APP_WEBSITE_DOMAIN;
        root         /usr/share/nginx/html;

        # Load configuration files for the default server block.
        include /etc/nginx/default.d/*.conf;

        location = /health {
            access_log off;
            add_header 'Content-Type' 'application/json';
            return 200 '{"status":"UP"}';
        }

EOF

if [[ "$ELEMENT_BACKEND" != "" ]]; then
cat <<EOF >> /etc/nginx/nginx.conf
        location /element {
            # Add trailing slashes to paths
            rewrite ^([^.]*[^/])\$ \$scheme://\$server_name\$1/ permanent;
        }

        # Make conditional
        location /element/ {
            proxy_pass $ELEMENT_BACKEND;
            proxy_set_header X-Forwarded-For \$remote_addr;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header Host \$host;
            # Synapse responses may be chunked, which is an HTTP/1.1 feature.
            proxy_http_version 1.1;
        }


        # We are not doing federation for the time being
        #     # For the federation port
        #     listen 8448 ssl default_server;
        #     listen [::]:8448 ssl default_server;
        location ~ ^(/_matrix|/_synapse/client) {
            proxy_pass $MATRIX_BACKEND;
            proxy_set_header X-Forwarded-For \$remote_addr;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_set_header Host \$host;

            # Nginx by default only allows file uploads up to 1M in size
            # Increase client_max_body_size to match max_upload_size defined in homeserver.yaml
            client_max_body_size 50M;
        
            # Synapse responses may be chunked, which is an HTTP/1.1 feature.
            proxy_http_version 1.1;
        }

        location /.well-known/matrix/client {
            return 200 '{"m.homeserver": {"base_url": "https://$APP_WEBSITE_DOMAIN"}}';
            default_type application/json;
            add_header Access-Control-Allow-Origin *;
        }
EOF
fi

cat <<EOF >> /etc/nginx/nginx.conf
        location / {
            proxy_pass       $WEBAPP_BACKEND;
            proxy_redirect   off;
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Host \$host;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_no_cache 1;
            proxy_cache_bypass 1;
            # Mostly for files, swoole has a 10MB limit
            client_max_body_size 11m;
        }

        location /meetmedia {
            proxy_pass $MEET_BACKEND;
            proxy_http_version 1.1;
            proxy_set_header Upgrade \$http_upgrade;
            proxy_set_header Connection \$connection_upgrade;
            proxy_set_header Host \$host;
        }

        location /meetmedia/api {
            proxy_pass $MEET_BACKEND;
            proxy_redirect   off;
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Host \$host;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_no_cache 1;
            proxy_cache_bypass 1;
        }

        location $WEBMAIL_PATH {
            proxy_pass       $ROUNDCUBE_BACKEND;
            proxy_redirect   off;
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Host \$host;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_no_cache 1;
            proxy_cache_bypass 1;
        }

        location /chwala {
            proxy_pass       $ROUNDCUBE_BACKEND;
            proxy_redirect   off;
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Host \$host;
            proxy_set_header X-Forwarded-Proto \$scheme;
            proxy_no_cache 1;
            proxy_cache_bypass 1;
        }

        location /Microsoft-Server-ActiveSync {
            proxy_pass       $ROUNDCUBE_BACKEND;
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_send_timeout 910s;
            proxy_read_timeout 910s;
            fastcgi_send_timeout 910s;
            fastcgi_read_timeout 910s;
        }

        location ~* ^/\.well-known/(caldav|carddav) {
            auth_request     /auth;
            auth_request_set \$dav_backend \$upstream_http_davbackend;

            # We can't dynamically set the proxy target with vanilla nginx. We can only select between a list of predefined options via map.
            proxy_pass \$backend_url;
            proxy_redirect   http:// \$scheme://;
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        }

        location /dav {
            auth_request     /auth;
            auth_request_set \$dav_backend \$upstream_http_davbackend;

            # We can't dynamically set the proxy target with vanilla nginx. We can only select between a list of predefined options via map.
            proxy_pass \$backend_url;
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        }

        location /freebusy {
            proxy_pass       $FREEBUSY_BACKEND$FREEBUSY_PATH;
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        }

        # static files
        location ^~ /browser {
            proxy_pass $COLLABORA_BACKEND;
            proxy_set_header Host \$http_host;
        }

        # WOPI discovery URL
        location ^~ /hosting/discovery {
            proxy_pass $COLLABORA_BACKEND;
            proxy_set_header Host \$http_host;
        }

        # Capabilities
        location ^~ /hosting/capabilities {
            proxy_pass $COLLABORA_BACKEND;
            proxy_set_header Host \$http_host;
        }

        # main websocket
        location ~ ^/cool/(.*)/ws\$ {
            proxy_pass $COLLABORA_BACKEND;
            proxy_set_header Upgrade \$http_upgrade;
            proxy_set_header Connection "Upgrade";
            proxy_set_header Host \$http_host;
            proxy_read_timeout 36000s;
        }

        # download, presentation and image upload
        location ~ ^/(c|l)ool {
            proxy_pass $COLLABORA_BACKEND;
            proxy_set_header Host \$http_host;
        }

        # Admin Console websocket
        location ^~ /cool/adminws {
            proxy_pass $COLLABORA_BACKEND;
            proxy_set_header Upgrade \$http_upgrade;
            proxy_set_header Connection "Upgrade";
            proxy_set_header Host \$http_host;
            proxy_read_timeout 36000s;
        }

        location = /auth {
            internal;
            proxy_pass              $WEBAPP_BACKEND/api/webhooks/nginx-httpauth;
            proxy_pass_request_body off;
            proxy_set_header        Content-Length "";
            proxy_set_header        X-Original-URI \$request_uri;

            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto \$scheme;
        }

        location /healthz {
            auth_basic          off;
            allow               all;
            return              200;
        }

        error_page 404 /404.html;
            location = /40x.html {
        }

        error_page 500 502 503 504 /50x.html;
            location = /50x.html {
        }
    }
}

mail {
    server_name         $APP_WEBSITE_DOMAIN;
    auth_http           $WEBAPP_BACKEND/api/webhooks/nginx;

    proxy_pass_error_message on;
    proxy_smtp_auth on;
    xclient off;

    ssl_certificate $SSL_CERTIFICATE;
    ssl_certificate_key $SSL_CERTIFICATE_KEY;

    ssl_protocols TLSv1 TLSv1.1 TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    server {
        listen 6143;
        protocol imap;

        proxy on;
        starttls on;
    }

    # Roundcube specific imap endpoint with proxy-protocol enabled
    server {
        listen 6144 proxy_protocol;
        protocol imap;
        proxy on;
        starttls on;
        auth_http           $WEBAPP_BACKEND/api/webhooks/nginx-roundcube;
    }

    server {
        listen 6465 ssl;
        protocol smtp;
        proxy on;
    }

    server {
        listen 6587;
        protocol smtp;
        proxy on;
        starttls on;
    }

    server {
        listen 6993 ssl;
        protocol imap;
        proxy on;
    }
}

stream {
    server {
        listen 6190;
        proxy_pass       $SIEVE_BACKEND;
    }
}
EOF

if [[ $1 == "validate" ]]; then
    exec nginx -t
else
    exec nginx -g "daemon off;"
fi
