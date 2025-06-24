FROM apheleia/swoole:latest

USER root

RUN dnf -y install \
        --setopt=install_weak_deps=False \
        --setopt 'tsflags=nodocs' \
        findutils gnupg2 git rsync && \
    dnf clean all

EXPOSE 8000

ARG GIT_REF=master
ARG GIT_REMOTE=https://git.kolab.org/source/kolab.git
ARG CONFIG=config.prod
ARG OVERLAY_GIT_REMOTE
ARG OVERLAY_GIT_REF=master
#Set this to prod or dev
ARG RELEASE_MODE=prod
COPY build.sh /build.sh
RUN /build.sh && \
    chgrp -R 0 /opt/app-root /tmp /etc/pki/ca-trust/extracted/ && \
    chown -R 1001:0 /opt/app-root /tmp /etc/pki/ca-trust/extracted/ && \
    chmod -R g=u /opt/app-root /tmp /etc/pki/ca-trust/extracted/ && \
    chmod -R 777 /etc/pki/ca-trust/extracted/

RUN chgrp -R 0 /etc/ && \
    chown -R 1001:0 /etc/ && \
    chmod -R g=u /etc/ && \
    chmod -R 777 /etc/

COPY init.sh /init.sh
COPY reload.sh /reload.sh
COPY update.sh /update.sh
COPY update-source.sh /update-source.sh

ENV MAX_FILESIZE="8M"
ENV MEMORY_LIMIT="128M"

USER 1001

CMD [ "/init.sh" ]
