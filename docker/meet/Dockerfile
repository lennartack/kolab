FROM apheleia/almalinux9

RUN dnf -y module install \
        --setopt 'tsflags=nodocs' \
        nodejs:20 && \
    dnf -y install \
        --setopt 'tsflags=nodocs' \
        npm nodejs python3 python3-pip meson ninja-build make gcc g++ git rsync && \
    dnf clean all

ARG GIT_REF=master
ARG GIT_REMOTE=https://git.kolab.org/source/kolab.git
ENV DEBUG="kolabmeet-server* mediasoup*"
COPY build.sh /build.sh
RUN /build.sh
COPY init.sh /init.sh
COPY update.sh /update.sh
CMD [ "/init.sh" ]
