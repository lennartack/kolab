FROM apheleia/almalinux9

RUN dnf -y install \
        --setopt 'tsflags=nodocs' \
        nginx \
        nginx-mod-mail \
        nginx-mod-stream && \
    dnf clean all

# Workaround because we have a relatively old nginx
# https://github.com/openssl/openssl/discussions/24810
RUN echo "Options = IgnoreUnexpectedEOF" >> /etc/crypto-policies/back-ends/opensslcnf.config

# ENV APP_WEBSITE_DOMAIN
# ENV SSL_CERTIFICATE
# ENV SSL_CERTIFICATE_KEY

COPY rootfs/ /

RUN chgrp -R 0 /etc/nginx /run /var/log/nginx && \
    chmod -R g=u /etc/nginx /run /var/log/nginx && \
    chown -R 1001:0 /etc/nginx /run /var/log/nginx

STOPSIGNAL SIGTERM

ENV WEBAPP_BACKEND http://webapp:8000
ENV MEET_BACKEND https://meet:12443
ENV ROUNDCUBE_BACKEND http://roundcube:8080
ENV DAV_BACKEND http://imap:11080
ENV DAV_PATH /dav
ENV DAV_ALTERNATIVE_BACKEND http://roundcube:8080
ENV DAV_ALTERNATIVE_PATH /dav
ENV FREEBUSY_BACKEND http://imap:11080
ENV FREEBUSY_PATH /freebusy
ENV COLLABORA_BACKEND http://collabora:9980
ENV WEBMAIL_PATH /roundcubemail
ENV SIEVE_BACKEND imap:4190
# ENV MATRIX_BACKEND http://synapse:8008
# ENV ELEMENT_BACKEND http://element:8880

USER 1001

CMD ["/init.sh"]

EXPOSE 6443/tcp 6465/tcp 6587/tcp 6143/tcp 6144/tcp 6993/tcp
