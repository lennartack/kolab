FROM apheleia/almalinux9

ARG REPOSITORY

RUN id cool || (groupadd -g 1001 cool && useradd -d /opt/cool/ -u 1001 -g 1001 cool)

RUN rpm --import "$REPOSITORY/repodata/repomd.xml.key" && \
    dnf config-manager --add-repo "$REPOSITORY" && \
    dnf -y --setopt tsflags= install \
    openssl \
    xmlstarlet \
    coolwsd \
    collaboraoffice-dict-de \
    collaboraofficebasis-de \
    collaboraoffice-dict-en \
    collaboraofficebasis-en-GB \
    collaboraoffice-dict-es \
    collaboraofficebasis-es \
    collaboraoffice-dict-fr \
    collaboraofficebasis-fr \
    collaboraoffice-dict-it \
    collaboraofficebasis-it \
    collaboraoffice-dict-nl \
    collaboraofficebasis-nl \
    collaboraoffice-dict-pt-BR \
    collaboraofficebasis-pt-BR \
    collaboraoffice-dict-pt-PT \
    collaboraofficebasis-pt \
    collaboraoffice-dict-ru \
    collaboraofficebasis-ru && \
    dnf -y --setopt tsflags= install \
    collabora-online-brand || : && \
    dnf clean all && \
    chown -R 1001:0 /etc/coolwsd /opt/ && \
    chmod -R g=u /etc/coolwsd /opt/

RUN sed -i -r -e 's/^SELINUX=.*$/SELINUX=permissive/g' /etc/selinux/config 2>/dev/null || :


WORKDIR /opt/cool/

ADD init.sh /init.sh
ADD coolwsd.xml /etc/coolwsd/coolwsd.xml

USER 1001

#Space separated list of hosts (where the service is running)
ENV ALLOWED_HOSTS="\.\*"
ENV SERVER_NAME=""

CMD ["/init.sh"]

EXPOSE 9980
