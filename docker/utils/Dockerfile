FROM apheleia/almalinux9

ENV HOME=/opt/app-root/src

# Install troubleshooting utilities
# FIXME not available under ppc64le
        # chromium \
        # chromedriver \
RUN dnf module -y enable ruby:3.1 && \
    dnf module -y enable php:8.2 && \
    dnf -y install \
        --setopt 'tsflags=nodocs' \
        bind-utils \
        bzip2 \
        cmake \
        cyrus-imapd \
        cyrus-sasl-plain \
        expat-devel \
        gcc \
        git \
        libjpeg-turbo-devel \
        lsof \
        whois \
        net-tools \
        nmap-ncat \
        openldap-clients \
        openssh-server \
        php \
        psmisc \
        iputils \
        mariadb \
        procps-ng \
        python3 \
        python3-devel \
        python3-pip \
        python3-pytz \
        strace \
        swaks \
        telnet \
        traceroute \
        vim-enhanced \
        wget \
        jq \
        ruby \
        ruby-devel \
        zlib-devel && \
    dnf clean all

RUN git clone https://github.com/libwbxml/libwbxml.git && \
    cd libwbxml && \
    mkdir build && \
    cd build && \
    cmake -D CMAKE_INSTALL_PREFIX=/usr .. && \
    make && \
    make install && \
    cd .. && \
    rm -rf libwbxml

 RUN pip3 install distro xmltodict dnspython dkimpy
 RUN env C_INCLUDE_PATH=/usr/include/libwbxml-1.1/wbxml pip3 install \
     --global-option=build_ext \
     git+https://github.com/Apheleia-IT/python-wbxml#egg=wbxml

# imapcli deps
RUN gem install thor net-imap

RUN id default || (groupadd -g 1001 default && useradd -d /opt/app-root/src/ -u 1001 -g 1001 default)

COPY /rootfs /

RUN chgrp -R 0 /opt/app-root/src && \
    chmod -R g=u /opt/app-root/src && \
    chown -R 1001:0 /opt/app-root/src

USER 1001

WORKDIR ${HOME}
