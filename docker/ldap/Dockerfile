FROM apheleia/almalinux9

RUN rpm --import https://mirror.apheleia-it.ch/repos/Kolab:/16/key.asc && \
    rpm -Uvh https://mirror.apheleia-it.ch/repos/Kolab:/16/kolab-16-for-el9.rpm

RUN sed -i -e '/^ssl/d' /etc/yum.repos.d/kolab*.repo && \
    dnf config-manager --enable kolab-16-testing &&\
    dnf -y --setopt tsflags= install kolab-schema 389-ds-base &&\
    dnf clean all

COPY init.sh /init.sh

RUN mkdir -p /data/config && \
    mkdir -p /data/ssca && \
    mkdir -p /data/run && \
    mkdir -p /var/run/dirsrv && \
    ln -s /data/config /etc/dirsrv/slapd-localhost && \
    ln -s /data/ssca /etc/dirsrv/ssca && \
    ln -s /data/run /var/run/dirsrv && \
    chmod -R 777 /data /etc/dirsrv

VOLUME [ "/data" ]

CMD [ "/usr/libexec/dirsrv/dscontainer", "-r" ]

EXPOSE 389/tcp
