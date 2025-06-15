FROM apheleia/almalinux9

ENV HOME=/opt/app-root/src

# Add kolab
RUN rpm --import https://mirror.apheleia-it.ch/repos/Kolab:/16/key.asc && \
    rpm -Uvh https://mirror.apheleia-it.ch/repos/Kolab:/16/kolab-16-for-el9.rpm

# Install php modules
RUN sed -i -e '/^ssl/d' /etc/yum.repos.d/kolab*.repo && \
    dnf config-manager --enable kolab-16 &&\
    dnf -y --setopt=install_weak_deps=False --setopt tsflags= install php-kolab php-kolabformat \
        composer \
        diffutils \
        file \
        git \
        make \
        unzip \
        curl-minimal \
        mariadb \
        which \
        rsync \
        openssl \
        httpd \
        patch \
        php-cli \
        php-common \
        php-ldap \
        php-opcache \
        php-pecl-apcu \
        php-mysqlnd \
        php-gd \
        php-fpm \
        php-pear \
        php-redis \
        ImageMagick \
        re2c \
        npm \
        wget && \
    dnf clean all

RUN npm install -g less less-plugin-clean-css

WORKDIR ${HOME}

COPY rootfs/opt/app-root/src/build.sh /opt/app-root/src/
COPY rootfs/opt/app-root/src/update.sh /opt/app-root/src/
COPY rootfs/opt/app-root/src/composer.json /opt/app-root/src/
COPY rootfs/opt/app-root/src/roundcubemail-config-templates /opt/app-root/src/roundcubemail-config-templates

ARG GIT_REF_ROUNDCUBEMAIL=dev/kolab-1.6
ARG GIT_REMOTE_ROUNDCUBEMAIL=https://git.kolab.org/source/roundcubemail.git
ARG GIT_REF_ROUNDCUBEMAIL_PLUGINS=master
ARG GIT_REMOTE_ROUNDCUBEMAIL_PLUGINS=https://git.kolab.org/diffusion/RPK/roundcubemail-plugins-kolab.git
ARG GIT_REF_CHWALA=master
ARG GIT_REMOTE_CHWALA=https://git.kolab.org/diffusion/C/chwala.git
ARG GIT_REF_SYNCROTON=master
ARG GIT_REMOTE_SYNCROTON=https://git.kolab.org/diffusion/S/syncroton.git
ARG GIT_REF_AUTOCONF=master
ARG GIT_REMOTE_AUTOCONF=https://git.kolab.org/diffusion/AC/autoconf.git
ARG GIT_REF_IRONY=master
ARG GIT_REMOTE_IRONY=https://git.kolab.org/source/iRony.git
ARG GIT_REF_FREEBUSY=master
ARG GIT_REMOTE_FREEBUSY=https://git.kolab.org/diffusion/F/freebusy.git
ARG GIT_REMOTE_SKIN_ELASTIC=""
ARG GIT_REF_SKIN_ELASTIC=master
ARG SKINS=kolab

RUN /opt/app-root/src/build.sh && \
    for PATH in /run/php-fpm /run/httpd /var/log/httpd /var/lib/httpd /data /opt/app-root; \
    do \
        /usr/bin/mkdir -p $PATH; \
        /usr/bin/chmod -R 777 $PATH; \
        /usr/bin/chown -R 1001:0 $PATH; \
        /usr/bin/chmod -R g=u $PATH; \
    done

COPY /rootfs /
RUN chmod 777 /opt/app-root/src
RUN chmod -R 777 /etc/php.ini /etc/httpd /opt/app-root/src/*.sh /tmp /etc/

VOLUME /data

ENV RUN_MIGRATIONS=true
ENV KOLABOBJECTS_COMPAT_MODE=false
ENV PER_USER_LOGGING=true
ENV LOGDRIVER=logfmt
ENV SKIN=kolab
ENV PRODUCT_NAME="Kolab Groupware"
# ENV SUPPORT_URL=
# ENV FILEAPI_KOLABFILES_BASEURI=
# ENV FILEAPI_WOPI_OFFICE=
# ENV FILE_API_URL=
# ENV FILE_API_SERVER_URL=
# ENV CALENDAR_CALDAV_SERVER=
# ENV TASKLIST_CALDAV_SERVER=
# ENV KOLAB_ADDRESSBOOK_CARDDAV_SERVER=
# ENV KOLAB_FILES_URL=
# ENV KOLAB_FILES_SERVER_URL=
# ENV KOLAB_API_URL =
# ENV IMAP_HOST=
# ENV IMAP_PORT=
# ENV IMAP_TLS=
# ENV IMAP_PROXY_PROTOCOL=
# ENV IMAP_ADMIN_LOGIN=
# ENV IMAP_ADMIN_PASSWORD=
# ENV DB_RC_USERNAME=
# ENV DB_RC_PASSWORD=
# ENV DB_RC_DATABASE=
# ENV DB_HOST=
# ENV DES_KEY=
# ENV APP_DOMAIN=
# ENV PROXY_WHITELIST=
# ENV SUBMISSION_HOST=
# ENV SUBMISSION_PORT=
# ENV SUBMISSION_ENCRYPTION=
# ENV DISABLED_PLUGINS=
# ENV EXTRA_PLUGINS=
# ENV SQL_DEBUG=
# ENV MEMCACHE_DEBUG=
# ENV IMAP_DEBUG=
# ENV SMTP_DEBUG=
# ENV DAV_DEBUG=
# ENV ACTIVESYNC_DEBUG=
# ENV KOLAB_API_DEBUG=
# ENV REDIS_HOST=
# ENV REDIS_PASSWORD=
# ENV PASSPORT_WEBMAIL_SSO_CLIENT_ID=
# ENV PASSPORT_WEBMAIL_SSO_CLIENT_SECRET=
ENV CALDAV_WELLKNOWN_REDIRECT_PATH="/dav/calendars/user"
ENV CARDDAV_WELLKNOWN_REDIRECT_PATH="/dav/addressbooks/user"
ENV MAX_CONCURRENT_REQUESTS="100"
ENV MAX_FILESIZE="50M"
ENV MEMORY_LIMIT="320M"

USER 1001

EXPOSE 8080

# https://httpd.apache.org/docs/2.4/stopping.html#gracefulstop
STOPSIGNAL SIGWINCH

CMD [ "/opt/app-root/src/init.sh" ]
