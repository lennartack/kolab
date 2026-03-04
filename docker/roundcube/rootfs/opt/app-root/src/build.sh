#!/bin/bash
set -e
set -x

pushd /opt/app-root/src/

function checkout() {
    if [ ! -d "$1" ]; then
        # If we clone the head of a branch, we can be much more efficient about it,
        # but it only works for commits that are the head of a branch. So we try, and resort to a full clone otherwise.
        # The same could be achieved using: git clone --depth 1 --branch dev/kolab-1.6 https://git.kolab.org/source/roundcubemail.git roundcubemail
        # but that only works with branch/tag names and not with a commit id (and since we pin commit ids we can't use that).
        mkdir "$1"
        pushd "$1"
        # Suppress warnings
        git config --global init.defaultBranch main
        git config --global advice.detachedHead false
        git init .
        git remote add origin "$2"
        if git fetch --depth 1 origin "$3"; then
            git checkout "$3"
            rm -rf .git
            echo "Successfully fetched $3"
            popd
        else
            echo "Resorting to full clone for $3"
            popd
            rm -Rf "$1"
            git clone "$2" "$1"
            pushd "$1"
            git checkout "$3" 
            rm -rf .git
            popd
        fi
    fi
}

# Clone what we don't find
checkout roundcubemail $GIT_REMOTE_ROUNDCUBEMAIL $GIT_REF_ROUNDCUBEMAIL
checkout roundcubemail-plugins-kolab $GIT_REMOTE_ROUNDCUBEMAIL_PLUGINS $GIT_REF_ROUNDCUBEMAIL_PLUGINS
checkout syncroton $GIT_REMOTE_SYNCROTON $GIT_REF_SYNCROTON
checkout iRony $GIT_REMOTE_IRONY $GIT_REF_IRONY
checkout chwala $GIT_REMOTE_CHWALA $GIT_REF_CHWALA
checkout autoconf $GIT_REMOTE_AUTOCONF $GIT_REF_AUTOCONF
checkout freebusy $GIT_REMOTE_FREEBUSY $GIT_REF_FREEBUSY
if [[ "$GIT_REMOTE_SKIN_ELASTIC" != ""  ]]; then 
    checkout roundcubemail-skin-elastic $GIT_REMOTE_SKIN_ELASTIC $GIT_REF_SKIN_ELASTIC
fi
if [[ "$GIT_REMOTE_SKIN_CHAMELEON" != ""  ]]; then 
    checkout larry $GIT_REMOTE_SKIN_LARRY $GIT_REF_SKIN_LARRY
    checkout roundcubemail-skin-chameleon $GIT_REMOTE_SKIN_CHAMELEON $GIT_REF_SKIN_CHAMELEON
fi
if [[ "$GIT_REMOTE_SKIN_CHAMELEON_ASSETS" != ""  ]]; then 
    checkout roundcubemail-skin-chameleon-assets $GIT_REMOTE_SKIN_CHAMELEON_ASSETS $GIT_REF_SKIN_CHAMELEON_ASSETS
fi

pushd roundcubemail
cp /opt/app-root/src/composer.json composer.json
rm -rf vendor/ composer.lock
env COMPOSER_ALLOW_SUPERUSER=1 php -dmemory_limit=-1 $(command -v composer) install
popd

./update.sh
pushd roundcubemail

# Adjust the configs
sed -i -r \
    -e "s/'vlv'(\s+)=> false,/'vlv'\1=> true,/g" \
    -e "s/'vlv_search'(\s+)=> false,/'vlv_search'\1=> true,/g" \
    -e "s/inetOrgPerson/inetorgperson/g" \
    -e "s/kolabInetOrgPerson/inetorgperson/g" \
    config/*.inc.php

sed -i -r -e "s|\$config\['managesieve_host'\] = .*$|\$config['managesieve_host'] = 'kolab';|g" config/managesieve.inc.php

popd

# Set the php timezone
sed -i -r -e 's|^(;*)date\.timezone.*$|date.timezone = Europe/Zurich|g' /etc/php.ini
# Allow environment variables from fpm
sed -i -e "s/;clear_env/clear_env/" /etc/php-fpm.d/www.conf
