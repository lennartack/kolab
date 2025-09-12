#!/bin/bash
set -e
set -x

cd /opt/app-root/src/

if [ "$1" == "--refresh" ]; then
    /update.sh
    shift
else
    /update-source.sh
fi

./artisan route:list

EXCLUDE_GROUPS=${EXCLUDE_GROUPS:-"skipci,ldap,coinbase,mollie,stripe,meet,dns,slow"}

if [ "$1" == "browsertest" ]; then
    ./artisan octane:start --port=80 &
    echo "127.0.0.1 kolab.local admin.kolab.local reseller.kolab.local" >> /etc/hosts

    php \
        -dmemory_limit=-1 \
        vendor/bin/phpunit \
        --exclude-group "$EXCLUDE_GROUPS" \
        --testsuite Browser
elif [ "$1" == "testsuite" ]; then
    php \
        -dmemory_limit=-1 \
        vendor/bin/phpunit \
        --exclude-group "$EXCLUDE_GROUPS" \
        --testsuite Unit

    php \
        -dmemory_limit=-1 \
        vendor/bin/phpunit \
        --exclude-group "$EXCLUDE_GROUPS" \
        --testsuite Feature
elif [ "$1" == "quicktest" ]; then
    php \
        -dmemory_limit=-1 \
        vendor/bin/phpunit \
        --exclude-group "$EXCLUDE_GROUPS" \
        --stop-on-defect \
        --stop-on-error \
        --stop-on-failure \
        --testsuite Unit

    php \
        -dmemory_limit=-1 \
        vendor/bin/phpunit \
        --exclude-group "$EXCLUDE_GROUPS" \
        --stop-on-defect \
        --stop-on-error \
        --stop-on-failure \
        --testsuite Feature
elif [ "$1" == "shell" ]; then
    exec /bin/bash
elif [ "$1" == "lint" ]; then
    php vendor/bin/php-cs-fixer check --using-cache=no --diff --verbose

    php -dmemory_limit=-1 vendor/bin/phpstan analyse

    npm run lint
elif [ "$1" == "profile" ]; then
    shift

    cat << EOF > /etc/php.d/xdebug.ini
zend_extension= usr/lib64/php/modules/xdebug.so

# Profiler config for xdebug3
xdebug.mode=profile
xdebug.output_dir="/output/"

EOF

    php \
        -dmemory_limit=-1 \
        vendor/bin/phpunit \
        --exclude-group "$EXCLUDE_GROUPS" \
        --stop-on-defect \
        --stop-on-error \
        --stop-on-failure \
        $@
else

    if [[ "$1" =~ "Browser" ]]; then
        echo "Assuming a browsertest and starting octane"
        ./artisan octane:start --port=80 &
        echo "127.0.0.1 kolab.local admin.kolab.local reseller.kolab.local" >> /etc/hosts
    fi

    php \
        -dmemory_limit=-1 \
        vendor/bin/phpunit \
        --exclude-group "$EXCLUDE_GROUPS" \
        --stop-on-defect \
        --stop-on-error \
        --stop-on-failure \
        $@
fi
