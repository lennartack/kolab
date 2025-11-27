#!/bin/bash
set -e
set -x

REBUILD=false
# Update the sourcecode if available.
if [ -d /src/kolabsrc.orig ] || [ -d /src/overlay ]; then
    echo "----> Updating source"
    /update-source.sh
    REBUILD=true
fi

if [ -d /src/config.override ]; then
    rsync -av \
        /src/config.override/ /opt/app-root/src/config/
fi

cd /opt/app-root/src/

# We rely on the environment for configuration
# We have to do this before running composer, because that attempts to read the .env file too.
rm -f .env


if [[ $REBUILD == true ]]; then
    rm -rf storage/framework
    mkdir -p storage/framework/{sessions,views,cache}

    if grep -e "composer.json" -e "app" /tmp/rsync.output; then
        rm composer.lock
        # Must be before the first artisan command because those can fail otherwise)
        php -dmemory_limit=-1 $(command -v composer) install
    fi

    find bootstrap/cache/ -type f ! -name ".gitignore" -delete
    ./artisan clear-compiled
    ./artisan cache:clear

    # Only run npm if something relevant was updated
    if grep -e "package.json" -e "resources" /tmp/rsync.output; then
        npm run dev
    fi

    # Can fail if octane is not running
    ./artisan octane:reload || :
fi
