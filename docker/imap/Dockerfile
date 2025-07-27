FROM apheleia/almalinux9

WORKDIR /root/

RUN dnf -y install \
        --setopt 'tsflags=nodocs' \
        git \
        autoconf \
        automake \
        bison \
        cyrus-sasl-devel \
        flex \
        gcc \
        gcc-c++ \
        gperf \
        jansson-devel \
        libbsd-devel \
        libtool \
        libicu-devel \
        libuuid-devel \
        openssl-devel \
        pkgconfig \
        sqlite-devel \
        brotli-devel \
        libzstd-devel \
        libical-devel \
        libxml2-devel \
        libnghttp2-devel \
        shapelib \
        zlib-devel \
        pcre-devel \
        perl-devel \
        cyrus-sasl \
        cyrus-sasl-plain \
        perl-Cyrus \
        busybox \
        rsync && \
    dnf clean all

ARG GIT_REF=dev/kolab-3.6
ARG GIT_REMOTE=https://git.kolab.org/source/cyrus-imapd

ADD build.sh /build.sh
RUN /build.sh

COPY /rootfs /

VOLUME [ "/var/spool/imap" ]
VOLUME [ "/var/lib/imap" ]

RUN id default || (groupadd -g 1001 default && useradd -d /opt/app-root/ -u 1001 -g 1001 default)

RUN mkdir -p /opt/app-root/src
RUN PATHS=(/run /run/saslauthd /var/run /var/lib/imap /var/spool/imap /etc/pki/cyrus-imapd /opt/app-root) && \
    mkdir -p ${PATHS[@]} && \
    chmod 777 ${PATHS[@]} && \
    chown -R 1001:0 ${PATHS[@]} && \
    chmod -R g=u ${PATHS[@]}

RUN touch /etc/pki/cyrus-imapd/cyrus-imapd.bundle.pem
RUN PATHS=(/etc /etc/passwd /etc/saslauthd.conf /etc/cyrus.conf /etc/imapd.conf /etc/pki/cyrus-imapd/cyrus-imapd.bundle.pem) && \
    chown 1001:0 ${PATHS[@]} && \
    chmod g=u ${PATHS[@]}

WORKDIR /opt/app-root/src

ENV SERVICES_PORT=8000
ENV IMAPD_CONF=/etc/imapd.conf
ENV CYRUS_CONF=/etc/cyrus.conf
ENV SERVERLIST=imap-backend
ENV MUPDATE=imap-mupdate
ENV SERVERNAME=imap-backend
ENV MAXLOGINS_PER_USER=50
ENV EXPIRY_DELAY=28
# Seems to be required on ppc64le only? Not sure why
ENV LD_LIBRARY_PATH=/usr/lib/
# ENV TLS_SERVER_CA_FILE
# ENV APP_SERVICES_DOMAIN
# ENV ROLE
# ENV WITH_TLS
# ENV WITH_TAGS
# ENV SYNC_HOST

CMD ["/init.sh"]

EXPOSE 11143/tcp 11993/tcp 11080/tcp 11443/tcp 11024/tcp 4190/tcp
