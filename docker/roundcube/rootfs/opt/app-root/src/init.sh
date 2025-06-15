#!/bin/bash

echo "Starting"
set -e
set -x

mkdir -p /data/pgp-home
chmod 777 /data/pgp-home

pushd /opt/app-root/src/

# Set the maximum number of simultaneous requests in php-fpm
sed -i "s/MAX_CONCURRENT_REQUESTS/$MAX_CONCURRENT_REQUESTS/" /etc/php-fpm.d/www.conf
sed -i "s/MAX_FILESIZE/$MAX_FILESIZE/" /etc/php.ini
sed -i "s/MEMORY_LIMIT/$MEMORY_LIMIT/" /etc/php.ini

if [[ $1 == "" ]]; then
    if [ -d /src.orig/ ]; then
        echo "----> Updating source"
        ./update.sh
    fi
fi

## Copy our configs over the default ones
cp /opt/app-root/src/roundcubemail-config-templates/* roundcubemail/config/

sed -i "s/?>//" roundcubemail/config/config.inc.php
echo "$EXTRA_CONFIG" >> roundcubemail/config/config.inc.php
printf "\n?>" >> roundcubemail/config/config.inc.php

if [[ "$RUN_MIGRATIONS" == "true" ]]; then
    # Initialize the db
    if [[ "$DB_ROOT_PASSWORD" == "" ]]; then
        echo "Not using password"
        cat > /tmp/kolab-setup-my.cnf << EOF
[client]
host=${DB_HOST}
user=root
EOF
    else
        cat > /tmp/kolab-setup-my.cnf << EOF
[client]
host=${DB_HOST}
user=root
password=${DB_ROOT_PASSWORD}
EOF
    fi

    mysql --defaults-file=/tmp/kolab-setup-my.cnf <<EOF
CREATE DATABASE IF NOT EXISTS $DB_RC_DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS $DB_RC_USERNAME@'%' IDENTIFIED BY '$DB_RC_PASSWORD';
CREATE USER IF NOT EXISTS $DB_RC_USERNAME@'127.0.0.1' IDENTIFIED BY '$DB_RC_PASSWORD';
ALTER USER $DB_RC_USERNAME@'%' IDENTIFIED BY '$DB_RC_PASSWORD';
ALTER USER $DB_RC_USERNAME@'127.0.0.1' IDENTIFIED BY '$DB_RC_PASSWORD';
GRANT ALL PRIVILEGES ON $DB_RC_DATABASE.* TO $DB_RC_USERNAME@'%';
FLUSH PRIVILEGES;
EOF

    pushd roundcubemail
    # Run roundcube and plugin database initializations
    echo "Initializing tables..."
    bin/initdb.sh --dir SQL/ || :

    for plugin in $(find plugins -mindepth 1 -maxdepth 1 -type d | sort); do
        if [ ! -z "$(find ${plugin} -type d -name SQL)" ]; then
            for dir in $(find plugins/$(basename ${plugin})/ -type d -name SQL); do
                # Skip plugins with multiple drivers and no kolab driver
                if [ ! -z "$(echo $dir | grep driver)" ]; then
                    if [ -z "$(echo $dir | grep kolab)" ]; then
                        continue
                    fi
                fi

                bin/initdb.sh \
                    --dir $dir \
                    --package $(basename ${plugin}) \
                    >/dev/null 2>&1 || :
            done
        fi
    done

    popd
    roundcubemail/bin/initdb.sh --dir syncroton/docs/SQL/ || :
    roundcubemail/bin/initdb.sh --dir chwala/doc/SQL/ || :

    echo "Updating tables..."
    roundcubemail/bin/updatedb.sh --dir syncroton/docs/SQL/ --package syncroton || :
    roundcubemail/bin/updatedb.sh --dir roundcubemail/SQL/ --package roundcube || :
    roundcubemail/bin/updatedb.sh --dir roundcubemail/plugins/libkolab/SQL/ --package libkolab || :
    roundcubemail/bin/updatedb.sh --dir roundcubemail/plugins/calendar/drivers/kolab/SQL/ --package calendar-kolab || :
fi

echo ""
echo "Done, starting httpd..."

if [ "$1" == "syncroton" ]; then
    ./update-from-source.sh || :
    roundcubemail/bin/initdb.sh --dir syncroton/docs/SQL/ || :
    roundcubemail/bin/updatedb.sh --dir syncroton/docs/SQL/ --package syncroton

    sed -i "s/?>/\$config['activesync_test_username'] = 'john@kolab.org';\n?>/" roundcubemail/config/config.inc.php
    sed -i "s/?>/\$config['activesync_test_password'] = 'simple123';\n?>/" roundcubemail/config/config.inc.php
    sed -i "s/?>/\$config['activesync_test_host'] = 'http:\/\/localhost:8001';\n?>/" roundcubemail/config/config.inc.php
    sed -i -r -e "s/config\['activesync_init_subscriptions'\] =.*$/config['activesync_init_subscriptions'] = 0;/g" roundcubemail/config/kolab_syncroton.inc.php
    sed -i -r -e "s/config\['activesync_multifolder_blacklist_event'\] =.*$/config['activesync_multifolder_blacklist_event'] = array('windowsoutlook');/g" roundcubemail/config/kolab_syncroton.inc.php
    sed -i -r -e "s/config\['activesync_multifolder_blacklist_task'\] =.*$/config['activesync_multifolder_blacklist_task'] = array('windowsoutlook');/g" roundcubemail/config/kolab_syncroton.inc.php
    sed -i -r -e "s/config\['activesync_multifolder_blacklist_contact'\] =.*$/config['activesync_multifolder_blacklist_contact'] = array('windowsoutlook');/g" roundcubemail/config/kolab_syncroton.inc.php
    sed -i -r -e "s/config\['activesync_multifolder_blacklist_note'\] =.*$/config['activesync_multifolder_blacklist_note'] = array('windowsoutlook');/g" roundcubemail/config/kolab_syncroton.inc.php

    pushd syncroton

    for user in $DEBUG_USERS; do
        mkdir logs/$user
        chmod 777 logs/$user
    done

    php -S localhost:8001 &
    pushd tests

    if [ "$2" == "testsuite" ]; then
        php \
            -dmemory_limit=-1 \
            ../vendor/bin/phpunit \
            --testsuite Unit
        php \
            -dmemory_limit=-1 \
            ../vendor/bin/phpunit \
            --testsuite Sync
    elif [ "$2" == "quicktest" ]; then
        php \
            -dmemory_limit=-1 \
            ../vendor/bin/phpunit \
            --testsuite Unit
    elif [ "$2" == "lint" ]; then
        popd
        cp ../syncroton.phpstan.neon phpstan.neon
        php -dmemory_limit=-1 vendor/bin/phpstan
    elif [ "$2" == "shell" ]; then
        exec /bin/bash
    else
        php \
            -dmemory_limit=-1 \
            ../vendor/bin/phpunit \
            --stop-on-defect \
            --stop-on-error \
            --stop-on-failure \
            "$2"
    fi
elif [ "$1" == "irony" ]; then
    ./update-from-source.sh || :

    pushd iRony
    pushd test

    if [ "$2" == "testsuite" ]; then
        php \
            -dmemory_limit=-1 \
            ../vendor/bin/phpunit
    elif [ "$2" == "shell" ]; then
        exec /bin/bash
    else
        php \
            -dmemory_limit=-1 \
            ../vendor/bin/phpunit \
            --stop-on-defect \
            --stop-on-error \
            --stop-on-failure \
            "$2"
    fi
elif [ "$1" == "roundcubemail-plugins-kolab" ]; then
    ./update-from-source.sh || :
    # We run the tests from the plugins directory, which we don't normally update
    if [ -d /src.orig/roundcubemail-plugins-kolab ]; then
        rsync -av \
            --no-links \
            --exclude=vendor \
            --exclude=temp \
            --exclude=config \
            --exclude=logs \
            --exclude=.git \
            --exclude=config.inc.php \
            --exclude=composer.json \
            --exclude=composer.lock \
            /src.orig/roundcubemail-plugins-kolab/ /opt/app-root/src/roundcubemail-plugins-kolab
    fi

    pushd roundcubemail-plugins-kolab
    ln -s ../roundcubemail/tests tests
    ln -s ../roundcubemail/program program

    if [ "$2" == "testsuite" ]; then
        #FIXME this doesn't currently work:
        #* set logging to stdout
        #* add test configuration
        #* there's some error about serializing a libcalendaring object to a string?
        php \
            -dmemory_limit=-1 \
            ../roundcubemail/vendor/bin/phpunit
    elif [ "$2" == "lint" ]; then
        cp ../roundcubemail-plugins-kolab.phpstan.neon phpstan.neon
        php -dmemory_limit=-1 ../roundcubemail/vendor/bin/phpstan
        php ../roundcubemail/vendor/bin/php-cs-fixer fix --dry-run --using-cache=no --diff --verbose
    elif [ "$2" == "shell" ]; then
        exec /bin/bash
    else
        php \
            -dmemory_limit=-1 \
            ../roundcubemail/vendor/bin/phpunit \
            --stop-on-defect \
            --stop-on-error \
            --stop-on-failure \
            "$2"
    fi
elif [ "$1" == "phpstan" ]; then
    ./update-from-source.sh || :
    pushd roundcubemail
    cp /src.orig/roundcubemail-plugins-kolab/phpstan.neon .
    cp /src.orig/roundcubemail-plugins-kolab/phpstan.bootstrap.php .
    php -dmemory_limit=-1 vendor/bin/phpstan analyse
elif [ "$1" == "shell" ]; then
    exec /bin/bash
else
    sed -i \
        -e "s|CALDAV_WELLKNOWN_REDIRECT_PATH|$CALDAV_WELLKNOWN_REDIRECT_PATH|" \
        -e "s|CARDDAV_WELLKNOWN_REDIRECT_PATH|$CARDDAV_WELLKNOWN_REDIRECT_PATH|" \
        /etc/httpd/conf.d/well-known.conf
    /usr/sbin/php-fpm
    exec httpd -DFOREGROUND
fi
